<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Internal;

/**
 * @phpstan-type StoredJobRow array{
 *     id: int,
 *     queue: string,
 *     type: string,
 *     status: \Oeltima\SimpleQueue\Contract\JobStatus,
 *     payload: string,
 *     attempts: int,
 *     max_attempts: int,
 *     available_at: string,
 *     started_at: ?string,
 *     completed_at: ?string,
 *     locked_by: ?string,
 *     locked_at: ?string,
 *     lease_token: ?string,
 *     error_message: ?string,
 *     error_trace: ?string,
 *     progress: ?int,
 *     progress_message: ?string,
 *     result: ?string,
 *     request_id: ?string,
 *     created_at: string,
 *     updated_at: string
 * }
 *
 * @internal
 */
final class InMemoryJobRow
{
    private function __construct()
    {
    }
}
