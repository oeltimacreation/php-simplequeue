<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Contract;

/** Read-only ownership inspection without payload/result hydration. */
interface SupportsClaimInspection
{
    /**
     * @param ClaimedJob $claim Execution identity
     * @return bool Whether the running row still belongs to this lease
     */
    public function isClaimOwned(ClaimedJob $claim): bool;
}
