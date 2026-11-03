<?php

declare(strict_types=1);

namespace ReynoTECH\Approvals\Contracts;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use ReynoTECH\Approvals\Models\Modification;

/**
 * Implemented by models that use the RequiresApprovalWhenChanges concern:
 * models whose changes can be captured as a pending Modification instead
 * of being saved directly.
 */
interface Approvable
{
    /**
     * The modifications recorded for this model.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany
     */
    public function modifications(): MorphMany;

    /**
     * Carry out a decision on a modification, once enough votes are in. Runs in the vote's transaction.
     *
     * A class using the RequiresApprovalWhenChanges concern gets this for free. One implementing it
     * by hand must close the modification: set `status` to approved or rejected, and save it. If it's
     * still pending afterwards the decision counts as refused, with no exception: the vote and every
     * database effect are undone, and approve()/disapprove() return false.
     *
     * @param  \ReynoTECH\Approvals\Models\Modification  $modification
     * @param  bool  $approved  True to apply the change, false to reject it.
     * @return void
     */
    public function applyModificationChanges(Modification $modification, bool $approved): void;
}
