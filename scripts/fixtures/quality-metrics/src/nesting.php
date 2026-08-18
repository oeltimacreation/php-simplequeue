<?php

declare(strict_types=1);

final class NestingFixture
{
    /**
     * Exercise three nested control-flow levels.
     *
     * @param bool $outer Whether to enter the outer branch
     * @param array<int, int> $values Values to inspect
     * @return int First positive value or zero
     */
    public function nested(bool $outer, array $values): int
    {
        if ($outer) {
            foreach ($values as $value) {
                if ($value > 0) {
                    return $value;
                }
            }
        }

        return 0;
    }
}
