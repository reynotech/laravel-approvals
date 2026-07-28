<?php

declare(strict_types=1);

namespace ReynoTECH\Approvals\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use ReynoTECH\Approvals\Models\Modification;
use ReynoTECH\Approvals\Support\ResolvesApprovalModels;

/**
 * Applied to the model that casts approvals/disapprovals on a Modification
 * (e.g. your User model).
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

        if ($disapproval = $this->disapprovals()->where([
            'disapprover_id' => $this->{$this->primaryKey},
            'disapprover_type' => static::class,
            'modification_id' => $modification->id,
        ])->first()) {
            $disapproval->delete();
        }

        $approvalModel = ResolvesApprovalModels::approvalClass();
        $approvalModel::firstOrCreate([
            'approver_id' => $this->{$this->primaryKey},
            'approver_type' => static::class,
            'modification_id' => $modification->id,
            'reason' => $reason,
        ]);

        $modification->fresh();

        if ($modification->approversRemaining == 0) {
            if ($modification->modifiable_id === null) {
                $polymorphicModel = new $modification->modifiable_type();
                $polymorphicModel->applyModificationChanges($modification, true);
            } else {
                $modification->modifiable->applyModificationChanges($modification, true);
            }
        }

        return true;
    }

    public function disapprove(Modification $modification, ?string $reason = null): bool
    {
        if (!$this->authorizedToDisapprove($modification)) {
            return false;
        }

        if ($approval = $this->approvals()->where([
            'approver_id' => $this->{$this->primaryKey},
            'approver_type' => static::class,
            'modification_id' => $modification->id,
        ])->first()) {
            $approval->delete();
        }

        $disapprovalModel = ResolvesApprovalModels::disapprovalClass();
        $disapprovalModel::firstOrCreate([
            'disapprover_id' => $this->{$this->primaryKey},
            'disapprover_type' => static::class,
            'modification_id' => $modification->id,
            'reason' => $reason,
        ]);

        $modification->fresh();

        if ($modification->disapproversRemaining == 0) {
            if ($modification->modifiable_id === null) {
                $polymorphicModel = new $modification->modifiable_type();
                $polymorphicModel->applyModificationChanges($modification, false);
            } else {
                $modification->modifiable->applyModificationChanges($modification, false);
            }
        }

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
}
