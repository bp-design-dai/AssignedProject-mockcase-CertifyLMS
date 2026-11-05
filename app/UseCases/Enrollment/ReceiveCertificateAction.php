<?php

declare(strict_types=1);

namespace App\UseCases\Enrollment;

use App\Enums\EnrollmentStatus;
use App\Exceptions\Enrollment\CompletionNotEligibleException;
use App\Exceptions\Enrollment\EnrollmentNotLearningException;
use App\Models\Certificate;
use App\Models\Enrollment;
use App\Services\CertificatePdfService;
use App\Services\CompletionEligibilityService;
use App\Services\EnrollmentStatusChangeService;
use App\UseCases\Certificate\IssueAction as IssueCertificateAction;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ReceiveCertificateAction
{
    public function __construct(
        private readonly CompletionEligibilityService $eligibility,
        private readonly EnrollmentStatusChangeService $statusChanger,
        private readonly IssueCertificateAction $issueCertificate,
        private readonly CertificatePdfService $pdf,
    ) {}

    public function __invoke(Enrollment $enrollment): Certificate
    {
        $issued = null;

        try {
            return DB::transaction(function () use ($enrollment, &$issued) {
                $locked = Enrollment::lockForUpdate()->findOrFail($enrollment->id);

                if ($locked->status === EnrollmentStatus::Passed) {
                    $existing = $locked->certificate()->lockForUpdate()->first();
                    if ($existing !== null) {
                        return $existing;
                    }
                }

                if ($locked->status !== EnrollmentStatus::Learning) {
                    throw new EnrollmentNotLearningException;
                }

                if (! $this->eligibility->isEligible($locked)) {
                    throw new CompletionNotEligibleException;
                }

                $locked->update([
                    'status' => EnrollmentStatus::Passed->value,
                    'passed_at' => now(),
                ]);
                $this->statusChanger->recordStatusChange(
                    $locked,
                    fromStatus: EnrollmentStatus::Learning,
                    toStatus: EnrollmentStatus::Passed,
                    changedBy: $locked->user,
                    reason: '受講生による修了証受領',
                );

                return $issued = ($this->issueCertificate)($locked);
            });
        } catch (Throwable $exception) {
            // The issuance savepoint may succeed while the enclosing commit fails.
            if ($issued !== null) {
                $this->pdf->discard($issued->pdf_path);
            }

            throw $exception;
        }
    }
}
