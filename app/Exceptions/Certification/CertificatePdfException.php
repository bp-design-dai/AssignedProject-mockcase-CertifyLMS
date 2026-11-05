<?php

declare(strict_types=1);

namespace App\Exceptions\Certification;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

final class CertificatePdfException extends HttpException
{
    public function __construct(Throwable $previous)
    {
        parent::__construct(503, '修了証PDFを作成できませんでした。時間をおいて再度受領してください。', $previous);
    }

    public function render(Request $request): ?RedirectResponse
    {
        return $request->expectsJson() ? null : redirect()->back()->with('error', $this->getMessage());
    }
}
