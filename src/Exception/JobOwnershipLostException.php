<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Exception;

/** Cooperative execution stopped because its durable lease no longer belongs to it. */
final class JobOwnershipLostException extends QueueException
{
}
