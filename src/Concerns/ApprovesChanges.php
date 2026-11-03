<?php

declare(strict_types=1);

namespace ReynoTECH\Approvals\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use ReynoTECH\Approvals\Models\Modification;
use ReynoTECH\Approvals\Models\ModificationItem;
use ReynoTECH\Approvals\Support\ResolvesApprovalModels;
use ReynoTECH\Approvals\Support\VoteNotApplied;

/**
 * Applied to the model that casts approvals/disapprovals on a Modification
 * (e.g. your User model).
 *
 * approve()/disapprove() are interoperable with both shapes a Modification
 * can take: when it has no ModificationItems it is decided as a whole
 * (legacy behavior); when it has items, every pending item is resolved
 * atomically instead. Use approveItem()/disapproveItem() to decide a single
 * item without touching the others.
 *
 * All four return false when the vote wasn't taken or couldn't be applied: the
 * approver isn't authorized, the change was already decided (or is an audit
 * row), its model no longer exists, or a listener vetoed the save.
 */
trait ApprovesChanges
{
    protected function authorizedToApprove(Modification $modification): bool
    {
        return true;
    }

    protected function authorizedToDisapprove(Modification $modification): bool
    {
        return true;
    }

    public function approve(Modification $modification, ?string $reason = null): bool
    {
        if (!$this->authorizedToApprove($modification)) {
            return false;
        }

        return $this->decideModification($modification, $reason, true);
    }

    public function disapprove(Modification $modification, ?string $reason = null): bool
    {
        if (!$this->authorizedToDisapprove($modification)) {
            return false;
        }

        return $this->decideModification($modification, $reason, false);
    }

    public function approveItem(ModificationItem $item, ?string $reason = null): bool
    {
        $modification = $item->modification;

        if (!$modification || !$this->authorizedToApprove($modification)) {
            return false;
        }

        return $this->decideItem($item, $reason, true);
    }

    public function disapproveItem(ModificationItem $item, ?string $reason = null): bool
    {
        $modification = $item->modification;

        if (!$modification || !$this->authorizedToDisapprove($modification)) {
            return false;
        }

        return $this->decideItem($item, $reason, false);
    }

    public function approvals(): MorphMany
    {
        return $this->morphMany(ResolvesApprovalModels::approvalClass(), 'approver');
    }

    public function disapprovals(): MorphMany
    {
        return $this->morphMany(ResolvesApprovalModels::disapprovalClass(), 'disapprover');
    }

    /**
     * The vote and its effect share a transaction, and the row is locked and re-read inside it: a
     * failed or vetoed write leaves no vote behind, and two approvers can't both decide the same change.
     */
    private function decideModification(Modification $modification, ?string $reason, bool $approving): bool
    {
        try {
            return $modification->getConnection()->transaction(function () use ($modification, $reason, $approving) {
                $locked = $modification->newQuery()->lockForUpdate()->find($modification->getKey());

                if (!$locked || !$locked->isPending() || ($approving && $this->modifiableIsMissing($locked))) {
                    return false;
                }

                if ($locked->items()->exists()) {
                    foreach ($locked->items()->pending()->get() as $item) {
                        if (!$this->castItemVote($item, $reason, $approving)) {
                            throw new VoteNotApplied();
                        }
                    }

                    return true;
                }

                $this->recordVote($locked, null, $reason, $approving);

                $reached = $approving ? $locked->approversRemaining <= 0 : $locked->disapproversRemaining <= 0;

                if ($reached) {
                    $this->applyToModifiable($locked, $approving);
                }

                if ($reached && $locked->newQuery()->find($locked->getKey())?->isPending()) {
                    throw new VoteNotApplied();
                }

                return true;
            });
        } catch (VoteNotApplied) {
            return false;
        }
    }

    private function decideItem(ModificationItem $item, ?string $reason, bool $approving): bool
    {
        try {
            return $item->getConnection()->transaction(function () use ($item, $reason, $approving) {
                $locked = $item->modification()->getQuery()->lockForUpdate()->first();
                $fresh = $item->fresh();

                if (!$locked || !$fresh || $fresh->status !== 'pending' || ($approving && $this->modifiableIsMissing($locked))) {
                    return false;
                }

                if (!$this->castItemVote($fresh, $reason, $approving)) {
                    throw new VoteNotApplied();
                }

                return true;
            });
        } catch (VoteNotApplied) {
            return false;
        }
    }

    private function castItemVote(ModificationItem $item, ?string $reason, bool $approving): bool
    {
        $this->recordVote($item->modification, $item, $reason, $approving);

        $item = $item->fresh();

        $reached = $approving ? $item->approversRemaining <= 0 : $item->disapproversRemaining <= 0;

        if ($reached) {
            $this->applyItemToModifiable($item, $approving);
        }

        $item->modification->recalculateStatusFromItems();

        return !$reached || $item->status !== 'pending';
    }

    /**
     * Each approver holds one vote per decision. The reason is only data: as part of the lookup,
     * voting again with a different one added a second vote that counted toward the quota.
     */
    private function recordVote(Modification $modification, ?ModificationItem $item, ?string $reason, bool $approving): void
    {
        $approvalModel = ResolvesApprovalModels::approvalClass();
        $disapprovalModel = ResolvesApprovalModels::disapprovalClass();

        $decision = [
            'modification_id' => $modification->id,
            'modification_item_id' => $item?->id,
        ];

        $approval = $decision + ['approver_id' => $this->getKey(), 'approver_type' => $this->getMorphClass()];
        $disapproval = $decision + ['disapprover_id' => $this->getKey(), 'disapprover_type' => $this->getMorphClass()];

        if ($approving) {
            $disapprovalModel::where($disapproval)->get()->each->delete();
            $approvalModel::firstOrCreate($approval, ['reason' => $reason]);
        } else {
            $approvalModel::where($approval)->get()->each->delete();
            $disapprovalModel::firstOrCreate($disapproval, ['reason' => $reason]);
        }
    }

    private function modifiableIsMissing(Modification $modification): bool
    {
        return $modification->modifiable_id !== null && $modification->modifiableWithDrafts() === null;
    }

    /**
     * An empty instance runs what needs no row: inserting a deferred creation, or rejecting a change
     * to a model that no longer exists.
     */
    private function resolveModifiable(Modification $modification)
    {
        return $modification->modifiableWithDrafts() ?? new ($modification->modifiableClass())();
    }

    private function applyToModifiable(Modification $modification, bool $approved): void
    {
        $this->resolveModifiable($modification)->applyModificationChanges($modification, $approved);
    }

    private function applyItemToModifiable(ModificationItem $item, bool $approved): void
    {
        $this->resolveModifiable($item->modification)->applyModificationItemChanges($item, $approved);
    }
}
