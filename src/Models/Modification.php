<?php

declare(strict_types=1);

namespace ReynoTECH\Approvals\Models;

use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use ReynoTECH\Approvals\Support\ResolvesApprovalModels;

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
        return $this->hasMany(ResolvesApprovalModels::approvalClass());
    }

    public function disapprovals(): HasMany
    {
        return $this->hasMany(ResolvesApprovalModels::disapprovalClass());
    }

    public function items(): HasMany
    {
        return $this->hasMany(ResolvesApprovalModels::modificationItemClass());
    }

    public function getApproversRemainingAttribute(): int
    {
        return $this->approvers_required - $this->approvals()->count();
    }

    public function getDisapproversRemainingAttribute(): int
    {
        return $this->disapprovers_required - $this->disapprovals()->count();
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

    public function forceApprovalUpdate(): void
    {
        $this->modifiable->applyModificationChanges($this, true);
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
