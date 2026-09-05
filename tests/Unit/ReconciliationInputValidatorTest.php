<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Unit;

use Oeltima\SimpleQueue\Internal\ReconciliationInputValidator;
use PHPUnit\Framework\TestCase;

final class ReconciliationInputValidatorTest extends TestCase
{
    public function testValidInputIsAccepted(): void
    {
        ReconciliationInputValidator::validateLimits(1_700_000_000, 250);
        ReconciliationInputValidator::validatePair(1, 1_700_000_000);
        $this->addToAssertionCount(1);
    }

    public function testEveryInvalidInputShapeIsRejected(): void
    {
        foreach (
            [
                static fn () => ReconciliationInputValidator::validateLimits(0, 1),
                static fn () => ReconciliationInputValidator::validateLimits(1, 0),
                static fn () => ReconciliationInputValidator::validatePair('bad', 1),
                static fn () => ReconciliationInputValidator::validatePair(0, 1),
                static fn () => ReconciliationInputValidator::validatePair(1, 'bad'),
                static fn () => ReconciliationInputValidator::validatePair(1, 0),
            ] as $operation
        ) {
            try {
                $operation();
                self::fail('Invalid reconciliation input must fail');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
