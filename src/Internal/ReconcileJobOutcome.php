<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Internal;

/** Individual result of reconciling one storage job with its queue notification. */
enum ReconcileJobOutcome
{
    case Restored;
    case Duplicate;
    case Invalid;
}
