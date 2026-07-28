<?php

declare(strict_types=1);

namespace ReynoTECH\Approvals\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;
use ReynoTECH\Approvals\Models\Modification;
use ReynoTECH\Approvals\Models\ModificationItem;
use ReynoTECH\Approvals\Support\ResolvesApprovalModels;

/**
 * Applied to the model that casts approvals/disapprovals on a Modification
 * (e.g. your User model).
 *
 * approve()/disapprove() are interoperable with both shapes a Modification
 * can take: when it has no ModificationItems it is decided as a whole
 * (legacy behavior); when it has items, every pending item is resolved
 * atomically instead. Use approveItem()/disapproveItem() to decide a single
 * item without touching the others.
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
        if (!$this->authorizedToApprove($item->modification)) {
            return false;
        }

        $this->castItemVote($item, $reason, true);

        return true;
    }

    public function disapproveItem(ModificationItem $item, ?string $reason = null): bool
    {
        if (!$this->authorizedToDisapprove($item->modification)) {
            return false;
        }

        $this->castItemVote($item, $reason, false);

        return true;
    }

    public function approvals(): MorphMany
    {
        return $this->morphMany(ResolvesApprovalModels::approvalClass(), 'approver');
    }

    public function disapprovals(): MorphMany
    {
        return $this->morphMany(ResolvesApprovalModels::disapprovalClass(), 'disapprover');
    }

    private function decideModification(Modification $modification, ?string $reason, bool $approving): bool
    {
        $modification = $modification->fresh();

        if ($modification->items()->exists()) {
            $pendingItems = $modification->items()->pending()->get();

            if ($pendingItems->isEmpty()) {
                return true;
            }

            DB::transaction(function () use ($pendingItems, $reason, $approving) {
                foreach ($pendingItems as $item) {
                    $this->castItemVote($item, $reason, $approving);
                }
            });

            return true;
        }

        $this->castGlobalVote($modification, $reason, $approving);

        $modification = $modification->fresh();

        if ($approving && $modification->approversRemaining <= 0) {
            $this->applyToModifiable($modification, true);
        } elseif (!$approving && $modification->disapproversRemaining <= 0) {
            $this->applyToModifiable($modification, false);
        }

        return true;
    }

    private function castItemVote(ModificationItem $item, ?string $reason, bool $approving): void
    {
        $this->recordVote($item->modification, $item, $reason, $approving);

        $item = $item->fresh();

        if ($approving && $item->approversRemaining <= 0) {
            $this->applyItemToModifiable($item, true);
        } elseif (!$approving && $item->disapproversRemaining <= 0) {
            $this->applyItemToModifiable($item, false);
        }

        $item->modification->recalculateStatusFromItems();
    }

    private function castGlobalVote(Modification $modification, ?string $reason, bool $approving): void
    {
        $this->recordVote($modification, null, $reason, $approving);
    }

    private function recordVote(Modification $modification, ?ModificationItem $item, ?string $reason, bool $approving): void
    {
        $approvalModel = ResolvesApprovalModels::approvalClass();
        $disapprovalModel = ResolvesApprovalModels::disapprovalClass();
        $itemId = $item?->id;

        if ($approving) {
            if ($disapproval = $disapprovalModel::where([
                'disapprover_id' => $this->{$this->primaryKey},
                'disapprover_type' => static::class,
                'modification_id' => $modification->id,
                'modification_item_id' => $itemId,
            ])->first()) {
                $disapproval->delete();
            }

            $approvalModel::firstOrCreate([
                'approver_id' => $this->{$this->primaryKey},
                'approver_type' => static::class,
                'modification_id' => $modification->id,
                'modification_item_id' => $itemId,
                'reason' => $reason,
            ]);
        } else {
            if ($approval = $approvalModel::where([
                'approver_id' => $this->{$this->primaryKey},
                'approver_type' => static::class,
                'modification_id' => $modification->id,
                'modification_item_id' => $itemId,
            ])->first()) {
                $approval->delete();
            }

            $disapprovalModel::firstOrCreate([
                'disapprover_id' => $this->{$this->primaryKey},
                'disapprover_type' => static::class,
                'modification_id' => $modification->id,
                'modification_item_id' => $itemId,
                'reason' => $reason,
            ]);
        }
    }

    private function applyToModifiable(Modification $modification, bool $approved): void
    {
        if ($modification->modifiable_id === null) {
            $polymorphicModel = new $modification->modifiable_type();
            $polymorphicModel->applyModificationChanges($modification, $approved);
        } else {
            $modification->modifiable->applyModificationChanges($modification, $approved);
        }
    }

    private function applyItemToModifiable(ModificationItem $item, bool $approved): void
    {
        $modification = $item->modification;

        if ($modification->modifiable_id === null) {
            $polymorphicModel = new $modification->modifiable_type();
            $polymorphicModel->applyModificationItemChanges($item, $approved);
        } else {
            $modification->modifiable->applyModificationItemChanges($item, $approved);
        }
    }
}
