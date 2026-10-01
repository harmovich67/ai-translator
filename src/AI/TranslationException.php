<?php

declare(strict_types=1);

namespace TrueVs\AiTranslator\AI;

use RuntimeException;

final class TranslationException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $detail = '')
    {
        parent::__construct($detail !== '' ? $detail : $reason);
    }

    public function hasDetail(): bool
    {
        return $this->getMessage() !== $this->reason;
    }
}
