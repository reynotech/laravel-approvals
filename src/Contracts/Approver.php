<?php

declare(strict_types=1);

namespace ReynoTECH\Approvals\Contracts;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use ReynoTECH\Approvals\Models\Modification;

/**
 * Implemented by models that use the ApprovesChanges concern: models that
 * can cast an approval/disapproval on a pending Modification.
 */
interface Approver
{
    /**
     * Vote to approve a modification.
     *
     * @param  \ReynoTECH\Approvals\Models\Modification  $modification
     * @param  string|null  $reason
     * @return bool  False when the vote wasn't taken or the change couldn't be applied.
     */
    public function approve(Modification $modification, ?string $reason = null): bool;

    /**
     * Vote to reject a modification.
     *
     * @param  \ReynoTECH\Approvals\Models\Modification  $modification
     * @param  string|null  $reason
     * @return bool  False when the vote wasn't taken or the rejection couldn't be applied.
     */
    public function disapprove(Modification $modification, ?string $reason = null): bool;

    /**
     * The approvals cast by this model.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany
     */
    public function approvals(): MorphMany;

    /**
     * The disapprovals cast by this model.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany
     */
    public function disapprovals(): MorphMany;
}
