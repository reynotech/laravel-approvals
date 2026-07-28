<?php

declare(strict_types=1);

namespace ReynoTECH\Approvals\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use ReynoTECH\Approvals\Support\ResolvesApprovalModels;

class Disapproval extends Model
{
    protected $table = 'disapprovals';

    protected $guarded = ['id'];

    public function disapprover(): MorphTo
    {
        return $this->morphTo();
    }

    public function modification(): BelongsTo
    {
        return $this->belongsTo(ResolvesApprovalModels::modificationClass());
    }

    public function modificationItem(): BelongsTo
    {
        return $this->belongsTo(ResolvesApprovalModels::modificationItemClass());
    }
}
