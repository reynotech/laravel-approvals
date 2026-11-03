<?php

declare(strict_types=1);

namespace ReynoTECH\Approvals\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use ReynoTECH\Approvals\Support\ResolvesApprovalModels;

/**
 * One disapprover's vote against a modification or one of its items.
 *
 * @property-read \Illuminate\Database\Eloquent\Model $disapprover
 * @property string|null $reason
 * @property int $modification_id
 * @property int|null $modification_item_id  Null when the vote is on the whole modification.
 */
class Disapproval extends Model
{
    protected $table = 'disapprovals';

    protected $guarded = ['id'];

    /**
     * The model that cast this vote.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo
     */
    public function disapprover(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The modification this vote belongs to.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function modification(): BelongsTo
    {
        return $this->belongsTo(ResolvesApprovalModels::modificationClass());
    }

    /**
     * The item this vote is on, if it isn't on the whole modification.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function modificationItem(): BelongsTo
    {
        return $this->belongsTo(ResolvesApprovalModels::modificationItemClass());
    }
}
