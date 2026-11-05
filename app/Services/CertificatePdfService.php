<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\Certification\CertificatePdfException;
use App\Models\Certificate;
use Illuminate\Support\Facades\Storage;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;
use RuntimeException;
use Throwable;

final class CertificatePdfService
{
    public function store(Certificate $certificate): void
    {
        try {
            $font = (string) config('certificates.pdf_font_path');
            if (! is_file($font) || ! is_readable($font)) {
                throw new RuntimeException('Japanese certificate font is not readable.');
            }

            $mpdf = new Mpdf([
                'mode' => 'utf-8',
                'format' => 'A4-L',
                'tempDir' => storage_path('app/private/mpdf-certificates'),
                'fontDir' => [...(new ConfigVariables)->getDefaults()['fontDir'], dirname($font)],
                'fontdata' => (new FontVariables)->getDefaults()['fontdata'] + [
                    'certificatejapanese' => ['R' => basename($font)],
                ],
                'default_font' => 'certificatejapanese',
            ]);
            $mpdf->WriteHTML(view('certificates.pdf', [
                'certificate' => $certificate->loadMissing(['user', 'certification']),
            ])->render());

            if (! Storage::disk('private')->put($certificate->pdf_path, $mpdf->OutputBinaryData())) {
                throw new RuntimeException('Certificate PDF could not be stored.');
            }
        } catch (Throwable $exception) {
            throw new CertificatePdfException($exception);
        }
    }

    public function discard(string $path): void
    {
        try {
            if (! Storage::disk('private')->delete($path)) {
                throw new RuntimeException('Certificate PDF cleanup failed.');
            }
        } catch (Throwable $exception) {
            // Keep the original issuance error available for the caller.
            report($exception);
        }
    }
}
