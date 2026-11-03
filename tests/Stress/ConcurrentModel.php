<?php

declare(strict_types=1);

namespace ReynoTECH\Approvals\Tests\Stress;

use Illuminate\Database\Eloquent\Model;
use ReynoTECH\Approvals\Concerns\RequiresApprovalWhenChanges;

class ConcurrentModel extends Model
{
    use RequiresApprovalWhenChanges;

    public const GROUPS = [];

    public int $required = 1;

    public bool $gate = false;

    protected $table = 'regression_models';

    protected $guarded = ['id'];

    protected function requiresApprovalWhenTrait(array $modifications): bool
    {
        return $this->gate;
    }

    protected function approversRequired(): int
    {
        return (int) (getenv('CONCURRENCY_REQUIRED') ?: 1);
    }

    protected function disapproversRequired(): int
    {
        return (int) (getenv('CONCURRENCY_REQUIRED') ?: 1);
    }

    protected function approvableItemGroups(array $dirtyKeys): array
    {
        return static::GROUPS;
    }
}
