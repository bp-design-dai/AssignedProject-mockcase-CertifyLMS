<?php

declare(strict_types=1);

namespace App\UseCases\Certificate;

use App\Models\Certificate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DownloadAction
{
    public function __invoke(Certificate $certificate): StreamedResponse
    {
        $path = $certificate->pdf_path;
        abort_unless(is_string($path) && preg_match('/\Acertificates\/[0-9A-HJKMNP-TV-Z]{26}\.pdf\z/i', $path), 404);

        $disk = Storage::disk('private');
        abort_unless($disk->fileExists($path), 404);

        return $disk->download($path, 'certificate.pdf', [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
