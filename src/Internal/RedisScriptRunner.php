<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Internal;

use Predis\ClientInterface;
use Predis\Response\ServerException;

/**
 * Runs Lua scripts through EVALSHA with an EVAL fallback after a script flush.
 *
 * Sending the SHA1 digest instead of the full script body on every invocation
 * reduces Redis wire payload bytes and lets Redis reuse the compiled script.
 * When Redis reports NOSCRIPT (for example after a script-cache flush), the
 * script body is sent once via EVAL and the digest is reused afterwards.
 *
 * @internal
 */
final class RedisScriptRunner
{
    /** @var array<string, string> */
    private array $shas = [];

    public function __construct(private readonly ClientInterface $redis)
    {
    }

    /**
     * Execute a cached Lua script with a one-shot EVAL fallback.
     *
     * @param string $body Lua script body
     * @param list<string> $keys Redis keys passed to the script
     * @param list<string> $arguments Non-key script arguments
     * @return mixed Raw Redis script response
     */
    public function run(string $body, array $keys, array $arguments): mixed
    {
        $sha = $this->shas[$body] ??= sha1($body);
        $payload = array_merge($keys, $arguments);
        $numKeys = count($keys);
        try {
            return $this->redis->evalsha($sha, $numKeys, ...$payload);
        } catch (ServerException $exception) {
            if ($exception->getErrorType() !== 'NOSCRIPT') {
                throw $exception;
            }

            return $this->redis->eval($body, $numKeys, ...$payload);
        }
    }
}
