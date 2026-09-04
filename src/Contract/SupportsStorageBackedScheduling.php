<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Contract;

/**
 * Marker for drivers whose dequeue path discovers due work from storage.
 *
 * Such drivers do not require a delayed notifier entry for future jobs,
 * because their claim already enforces the stored availability timestamp.
 */
interface SupportsStorageBackedScheduling
{
}
