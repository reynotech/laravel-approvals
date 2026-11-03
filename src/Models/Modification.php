<?php

declare(strict_types=1);

namespace ReynoTECH\Approvals\Models;

use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use ReynoTECH\Approvals\Scopes\ApprovalDraftScope;
use ReynoTECH\Approvals\Support\ResolvesApprovalModels;
use ReynoTECH\Approvals\Support\VoteNotApplied;

class Modification extends Model
{
    public const FLOW_DIRECT = 'direct';

    public const FLOW_APPROVAL = 'approval';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_PARTIALLY_RESOLVED = 'partially_resolved';

    protected $table = 'modifications';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'modifications' => AsArrayObject::class,
            'meta' => AsArrayObject::class,
            'context' => AsArrayObject::class,
            'active' => 'boolean',
            'is_modification' => 'boolean',
            'is_update' => 'boolean',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Modification $modification) {
            $modification->syncLegacyFlags();
        });
    }

    public function modifiable(): MorphTo
    {
        return $this->morphTo();
    }

    public function modifier(): MorphTo
    {
        return $this->morphTo();
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(ResolvesApprovalModels::approvalClass(), 'modification_id');
    }

    public function disapprovals(): HasMany
    {
        return $this->hasMany(ResolvesApprovalModels::disapprovalClass(), 'modification_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ResolvesApprovalModels::modificationItemClass(), 'modification_id');
    }

    public function getApproversRemainingAttribute(): int
    {
        return $this->approvers_required - $this->approvals()->whereNull('modification_item_id')->count();
    }

    public function getDisapproversRemainingAttribute(): int
    {
        return $this->disapprovers_required - $this->disapprovals()->whereNull('modification_item_id')->count();
    }

    /**
     * @deprecated alias of approvers_remaining
     */
    public function getApprovalsRemainingAttribute(): int
    {
        return $this->approversRemaining;
    }

    /**
     * @deprecated alias of disapprovers_remaining
     */
    public function getDisapprovalsRemainingAttribute(): int
    {
        return $this->disapproversRemaining;
    }

    /**
     * Whether this row is still waiting for a decision. Anything else, including a direct audit
     * row, can't take a vote: applying it again would overwrite whatever changed since.
     */
    public function isPending(): bool
    {
        return $this->flow === self::FLOW_APPROVAL && $this->status === self::STATUS_PENDING;
    }

    public function isCreation(): bool
    {
        return $this->is_update === false;
    }

    /**
     * The modifiable class, resolving a morph alias.
     */
    public function modifiableClass(): string
    {
        return Relation::getMorphedModel($this->modifiable_type) ?? $this->modifiable_type;
    }

    /**
     * The modifiable, including a row hidden while its creation waits for approval,
     * which the `modifiable` relation can't see.
     */
    public function modifiableWithDrafts(): ?Model
    {
        if ($this->modifiable_id === null) {
            return null;
        }

        // Not the relation: it would also look on this Modification's connection, not the model's.
        if ($this->relationLoaded('modifiable') && $this->getRelation('modifiable')) {
            return $this->getRelation('modifiable');
        }

        $class = $this->modifiableClass();
        $query = $class::withoutGlobalScope(ApprovalDraftScope::class);

        // A change restoring a soft-deleted model has to reach it.
        if (method_exists($class, 'getDeletedAtColumn')
            && array_key_exists((new $class())->getDeletedAtColumn(), $this->modifications->toArray())) {
            $query->withoutGlobalScope(SoftDeletingScope::class);
        }

        $model = $query->find($this->modifiable_id);

        $this->setRelation('modifiable', $model);

        return $model;
    }

    /**
     * Skips the votes, not the rules: only a pending change can be forced, and a rejected item
     * stays rejected. Anything else would overwrite whatever changed since it was decided.
     * All or nothing: a listener vetoing part of it leaves everything pending.
     */
    public function forceApprovalUpdate(): void
    {
        $status = $this->status;

        try {
            $this->getConnection()->transaction(function () {
                if (!$this->newQuery()->lockForUpdate()->find($this->getKey())?->isPending()) {
                    return;
                }

                // From the row, not an instance cached earlier: a stale one could see no change to write.
                $this->unsetRelation('modifiable');

                $model = $this->modifiable_id === null
                    ? new ($this->modifiableClass())()
                    : $this->modifiableWithDrafts();

                if (!$model) {
                    return;
                }

                if (!$this->items()->exists()) {
                    $model->applyModificationChanges($this, true);

                    if ($this->newQuery()->find($this->getKey())?->isPending()) {
                        throw new VoteNotApplied();
                    }

                    return;
                }

                foreach ($this->items()->pending()->get() as $item) {
                    $model->applyModificationItemChanges($item, true);

                    if ($item->status === 'pending') {
                        throw new VoteNotApplied();
                    }
                }

                $this->recalculateStatusFromItems();
            });
        } catch (VoteNotApplied) {
            $this->status = $status;
        } catch (\Throwable $e) {
            $this->status = $status;

            throw $e;
        }
    }

    /**
     * forceApprovalUpdate() for a creation, refusing anything else: a query that went wrong
     * must not apply a change to an existing row under this name.
     */
    public function forceApprovalCreate(): void
    {
        if (!$this->isCreation()) {
            throw new \LogicException("Modification [{$this->getKey()}] isn't a creation.");
        }

        $this->forceApprovalUpdate();
    }

    /**
     * Derives the legacy `active`/`is_modification` booleans from `flow`/
     * `status`, so old consumers reading those columns keep working without
     * changes: direct/applied => inactive audit row; approval/pending =>
     * active pending row; approval/approved|rejected|partially_resolved =>
     * inactive, resolved row.
     */
    public function syncLegacyFlags(): void
    {
        if (is_null($this->flow) || is_null($this->status)) {
            return;
        }

        if ($this->flow === self::FLOW_DIRECT) {
            $this->active = false;
            $this->is_modification = false;

            return;
        }

        $this->is_modification = true;
        $this->active = $this->status === self::STATUS_PENDING;
    }

    /**
     * Recomputes this modification's status from its items' statuses.
     * No-op (returns the current status) when there are no items, since a
     * Modification without items is decided as a whole by approve()/disapprove().
     */
    public function recalculateStatusFromItems(): string
    {
        $statuses = $this->items()->pluck('status');

        if ($statuses->isEmpty()) {
            return $this->status;
        }

        $resolved = ['applied', 'approved'];

        if ($statuses->contains('pending')) {
            $status = self::STATUS_PENDING;
        } elseif ($statuses->every(fn ($s) => in_array($s, $resolved, true))) {
            $status = self::STATUS_APPROVED;
        } elseif ($statuses->every(fn ($s) => $s === 'rejected')) {
            $status = self::STATUS_REJECTED;
        } else {
            $status = self::STATUS_PARTIALLY_RESOLVED;
        }

        $this->status = $status;
        $this->save();

        return $status;
    }

    public function scopeActiveOnly($query)
    {
        return $query->where('active', true);
    }

    public function scopeInactiveOnly($query)
    {
        return $query->where('active', false);
    }

    public function scopeChanges($query)
    {
        return $query->where('is_update', true);
    }

    public function scopeCreations($query)
    {
        return $query->where('is_update', false);
    }
}
