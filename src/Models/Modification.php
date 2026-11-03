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

/**
 * A change to a model: an audit row if it was saved directly, or a request if it's waiting for approval.
 *
 * Holds the change as a diff in `modifications`, and its votes in `approvals` and `disapprovals`.
 *
 * @property string|null $modifiable_type
 * @property int|null $modifiable_id  Null until a deferred creation is approved.
 * @property string|null $modifier_type
 * @property int|null $modifier_id
 * @property string|null $flow  One of the FLOW_ constants.
 * @property string|null $status  One of the STATUS_ constants.
 * @property string|null $event_type
 * @property string|null $operation_id
 * @property bool $is_update  False for a creation.
 * @property bool $active  Legacy flag, derived from `flow` and `status`.
 * @property bool $is_modification  Legacy flag, derived from `flow` and `status`.
 * @property int $approvers_required
 * @property int $disapprovers_required
 * @property string $md5  Identifies an identical pending change.
 * @property \Illuminate\Database\Eloquent\Casts\ArrayObject $modifications  The diff, keyed by attribute.
 * @property \Illuminate\Database\Eloquent\Casts\ArrayObject|null $meta
 * @property \Illuminate\Database\Eloquent\Casts\ArrayObject|null $context
 * @property \Illuminate\Support\Carbon|null $occurred_at  When the change was requested or saved.
 * @property-read int $approvers_remaining
 * @property-read int $disapprovers_remaining
 */
class Modification extends Model
{
    /**
     * An audit row for a change that was saved directly.
     */
    public const FLOW_DIRECT = 'direct';

    /**
     * A change held for approval.
     */
    public const FLOW_APPROVAL = 'approval';

    /**
     * Status of a direct audit row.
     */
    public const STATUS_APPLIED = 'applied';

    /**
     * Waiting for a decision.
     */
    public const STATUS_PENDING = 'pending';

    /**
     * Approved, and applied unless the model has `updateWhenApproved` off.
     */
    public const STATUS_APPROVED = 'approved';

    /**
     * Rejected. This is final: send the change again to retry it.
     */
    public const STATUS_REJECTED = 'rejected';

    /**
     * Its items are decided, some approved and some rejected.
     */
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

    /**
     * The model this change belongs to.
     *
     * Hides rows waiting for approval. Use modifiableWithDrafts() to reach them.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo
     */
    public function modifiable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The model recorded as the author of the change.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo
     */
    public function modifier(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The approvals cast on this modification and on its items.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function approvals(): HasMany
    {
        return $this->hasMany(ResolvesApprovalModels::approvalClass(), 'modification_id');
    }

    /**
     * The disapprovals cast on this modification and on its items.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function disapprovals(): HasMany
    {
        return $this->hasMany(ResolvesApprovalModels::disapprovalClass(), 'modification_id');
    }

    /**
     * The separately decidable parts of this change, if the model defines any.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function items(): HasMany
    {
        return $this->hasMany(ResolvesApprovalModels::modificationItemClass(), 'modification_id');
    }

    /**
     * Approvals still missing, read as `approvers_remaining`. Votes on items don't count.
     *
     * @return int
     */
    public function getApproversRemainingAttribute(): int
    {
        return $this->approvers_required - $this->approvals()->whereNull('modification_item_id')->count();
    }

    /**
     * Disapprovals still missing, read as `disapprovers_remaining`. Votes on items don't count.
     *
     * @return int
     */
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

    /**
     * Whether this requests or records a new row, not a change to an existing one.
     *
     * @return bool
     */
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

    /**
     * Only the rows flagged `active`, which are the pending ones.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActiveOnly($query)
    {
        return $query->where('active', true);
    }

    /**
     * Only the rows not flagged `active`: audit rows and decided changes.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeInactiveOnly($query)
    {
        return $query->where('active', false);
    }

    /**
     * Only changes to existing rows.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeChanges($query)
    {
        return $query->where('is_update', true);
    }

    /**
     * Only creations.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeCreations($query)
    {
        return $query->where('is_update', false);
    }
}
