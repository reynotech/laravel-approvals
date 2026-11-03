<?php

declare(strict_types=1);

namespace ReynoTECH\Approvals\Models;

use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use ReynoTECH\Approvals\Support\ResolvesApprovalModels;

/**
 * A single decidable unit inside a Modification, used only when a model
 * opts into per-field/per-group approval via approvableItemGroups(). A
 * Modification with no items is decided as a whole (legacy behavior).
 */
class ModificationItem extends Model
{
    /** Holds the changed fields that approvableItemGroups() didn't assign to any group. */
    public const KEY_UNGROUPED = '_ungrouped';

    protected $table = 'modification_items';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'original' => AsArrayObject::class,
            'proposed' => AsArrayObject::class,
            'context' => AsArrayObject::class,
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * The modification this item is part of.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function modification(): BelongsTo
    {
        return $this->belongsTo(ResolvesApprovalModels::modificationClass(), 'modification_id');
    }

    /**
     * The approvals cast on this item.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function approvals(): HasMany
    {
        return $this->hasMany(ResolvesApprovalModels::approvalClass(), 'modification_item_id');
    }

    /**
     * The disapprovals cast on this item.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function disapprovals(): HasMany
    {
        return $this->hasMany(ResolvesApprovalModels::disapprovalClass(), 'modification_item_id');
    }

    /**
     * Approvals still missing, read as `approvers_remaining`.
     *
     * @return int
     */
    public function getApproversRemainingAttribute(): int
    {
        return $this->approvers_required - $this->approvals()->count();
    }

    /**
     * Disapprovals still missing, read as `disapprovers_remaining`.
     *
     * @return int
     */
    public function getDisapproversRemainingAttribute(): int
    {
        return $this->disapprovers_required - $this->disapprovals()->count();
    }

    /**
     * Only the items waiting for a decision.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }
}
