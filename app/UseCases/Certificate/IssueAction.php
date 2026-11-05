<?php

declare(strict_types=1);

namespace App\UseCases\Certificate;

use App\Enums\EnrollmentStatus;
use App\Exceptions\Certification\CertificateAlreadyIssuedException;
use App\Exceptions\Certification\EnrollmentNotPassedException;
use App\Models\Certificate;
use App\Models\Enrollment;
use App\Services\CertificatePdfService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class IssueAction
{
    public function __construct(private readonly CertificatePdfService $pdf) {}

    public function __invoke(Enrollment $enrollment, ?CarbonInterface $issuedAt = null): Certificate
    {
        $path = null;

        try {
            return DB::transaction(function () use ($enrollment, $issuedAt, &$path) {
                $locked = Enrollment::lockForUpdate()->findOrFail($enrollment->id);
                if ($locked->status !== EnrollmentStatus::Passed || $locked->passed_at === null) {
                    throw new EnrollmentNotPassedException;
                }

                if ($locked->certificate()->lockForUpdate()->first() !== null) {
                    throw new CertificateAlreadyIssuedException;
                }

                $path = 'certificates/'.Str::ulid().'.pdf';
                $certificate = new Certificate([
                    'user_id' => $locked->user_id,
                    'enrollment_id' => $locked->id,
                    'certification_id' => $locked->certification_id,
                    'pdf_path' => $path,
                    'issued_at' => $issuedAt ?? now(),
                ]);

                $this->pdf->store($certificate);
                if (! $certificate->save()) {
                    throw new RuntimeException('Certificate record could not be saved.');
                }

                return $certificate;
            });
        } catch (Throwable $exception) {
            if ($path !== null) {
                $this->pdf->discard($path);
            }

            throw $exception;
        }
    }
}
