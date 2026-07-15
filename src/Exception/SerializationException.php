<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Exception;

/** Raised when queue payload or result JSON cannot be encoded or decoded. */
final class SerializationException extends QueueException
{
}
