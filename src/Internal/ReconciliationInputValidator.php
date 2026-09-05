<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Internal;

/** @internal */
final class ReconciliationInputValidator
{
    public static function validateLimits(int $now, int $pendingScanLimit): void
    {
        self::positiveInteger($now, 'Reconciliation current timestamp');
        self::positiveInteger($pendingScanLimit, 'Pending scan limit');
    }

    public static function validatePair(mixed $jobId, mixed $availableAt): void
    {
        self::positiveInteger($jobId, 'Reconciliation job ID');
        self::positiveInteger($availableAt, 'Reconciliation timestamp');
    }

    private static function positiveInteger(mixed $value, string $field): void
    {
        if (!is_int($value)) {
            throw new \InvalidArgumentException(sprintf('%s must be a positive integer', $field));
        }
        if ($value < 1) {
            throw new \InvalidArgumentException(sprintf('%s must be a positive integer', $field));
        }
    }
}
