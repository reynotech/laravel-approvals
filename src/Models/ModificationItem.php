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

    public function modification(): BelongsTo
    {
        return $this->belongsTo(ResolvesApprovalModels::modificationClass(), 'modification_id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(ResolvesApprovalModels::approvalClass(), 'modification_item_id');
    }

    public function disapprovals(): HasMany
    {
        return $this->hasMany(ResolvesApprovalModels::disapprovalClass(), 'modification_item_id');
    }

    public function getApproversRemainingAttribute(): int
    {
        return $this->approvers_required - $this->approvals()->count();
    }

    public function getDisapproversRemainingAttribute(): int
    {
        return $this->disapprovers_required - $this->disapprovals()->count();
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }
}
