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
    protected $table = 'modifications';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'modifications' => AsArrayObject::class,
            'meta' => AsArrayObject::class,
            'active' => 'boolean',
            'is_modification' => 'boolean',
            'is_update' => 'boolean',
        ];
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
