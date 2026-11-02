<?php

declare(strict_types=1);

namespace ReynoTECH\Approvals\Tests\Fixtures;

use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Model;
use ReynoTECH\Approvals\Concerns\RequiresApprovalWhenChanges;
use ReynoTECH\Approvals\Contracts\Approvable;
use Spatie\SchemalessAttributes\Casts\SchemalessAttributes;

/**
 * One column per kind of cast whose diff or write-back needs its own handling.
 */
class CastedApprovableModel extends Model implements Approvable
{
    use RequiresApprovalWhenChanges;

    protected $table = 'casted_models';

    protected $guarded = ['id'];

    protected $casts = [
        'starts_at' => 'datetime',
        'starts_immutable' => 'immutable_datetime',
        'starts_on' => 'date',
        'as_array' => 'array',
        'as_collection' => 'collection',
        'as_collection_class' => AsCollection::class,
        'as_array_object' => AsArrayObject::class,
        'extra' => SchemalessAttributes::class,
    ];

    public bool $gateChanges = false;

    /** @var array<string, list<string>> */
    public array $itemGroups = [];

    protected function requiresApprovalWhenTrait(array $modifications): bool
    {
        return $this->gateChanges;
    }

    protected function approvableItemGroups(array $dirtyKeys): array
    {
        return $this->itemGroups;
    }
}
