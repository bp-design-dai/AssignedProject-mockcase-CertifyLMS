<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Certificate;
use App\UseCases\Certificate\DownloadAction;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class CertificateDownloadController extends Controller
{
    public function __invoke(Certificate $certificate, DownloadAction $action): StreamedResponse
    {
        $this->authorize('download', $certificate);

        return $action($certificate);
    }
}
