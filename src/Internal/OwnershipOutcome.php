<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Internal;

/**
 * Exhaustive outcome of a fenced storage transition.
 *
 * @internal
 */
enum OwnershipOutcome
{
    case Owned;
    case Lost;

    /**
     * Determine whether the worker lost ownership of the job.
     *
     * @return bool True when queue acknowledgement must be withheld
     */
    public function isLost(): bool
    {
        return $this === self::Lost;
    }
}
