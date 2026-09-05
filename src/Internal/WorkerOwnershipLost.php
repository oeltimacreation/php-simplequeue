<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Internal;

/**
 * Internal control flow for aborted pipelines after lost ownership.
 *
 * @internal
 */
final class WorkerOwnershipLost extends \RuntimeException
{
}
