<?php

declare(strict_types=1);

namespace App\Exceptions\Certification;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * 同一 Enrollment に対して修了証が二重発行されようとした際の例外（HTTP 409）。
 * 発行前に受講登録行をロックし、既存の修了証を確認する。
 */
final class CertificateAlreadyIssuedException extends ConflictHttpException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('この受講登録の修了証はすでに発行されています。', $previous);
    }
}
