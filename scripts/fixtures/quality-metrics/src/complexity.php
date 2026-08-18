<?php

declare(strict_types=1);

final class ComplexityFixture
{
    /**
     * Exercise a branch and a boolean decision.
     *
     * @param bool $first First decision
     * @param bool $second Second decision
     * @return int Fixture result
     */
    public function branch(bool $first, bool $second): int
    {
        if ($first && $second) {
            return 1;
        }

        return 0;
    }
}
