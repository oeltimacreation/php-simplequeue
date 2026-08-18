<?php

declare(strict_types=1);

final class DuplicateFixtureOne
{
    /**
     * @param int $value Fixture value
     * @return int Repeated normalized sequence result
     */
    public function shared(int $value): int
    {
        $sum = 0;
        $sum += $value;
        $sum += $value;
        $sum += $value;
        $sum += $value;
        $sum += $value;
        $sum += $value;
        $sum += $value;
        $sum += $value;
        $sum += $value;
        $sum += $value;
        $sum += $value;
        $sum += $value;
        return $sum;
    }
}

final class DuplicateFixtureTwo
{
    /**
     * @param int $value Fixture value
     * @return int Repeated normalized sequence result
     */
    public function shared(int $value): int
    {
        $sum = 0;
        $sum += $value;
        $sum += $value;
        $sum += $value;
        $sum += $value;
        $sum += $value;
        $sum += $value;
        $sum += $value;
        $sum += $value;
        $sum += $value;
        $sum += $value;
        $sum += $value;
        $sum += $value;
        return $sum;
    }
}
