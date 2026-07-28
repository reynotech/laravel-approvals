<?php

declare(strict_types=1);

namespace ReynoTECH\Approvals\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use ReynoTECH\Approvals\Concerns\RequiresApprovalWhenChanges;
use ReynoTECH\Approvals\Contracts\Approvable;

/**
 * Same shape as ApprovableModel but opts into per-group ModificationItems:
 * `rules` and `notes` can be approved/rejected independently of each other.
 */
class GroupedApprovableModel extends Model implements Approvable
{
    use RequiresApprovalWhenChanges;

    protected $table = 'approvable_models';

    protected $guarded = ['id'];

    public bool $gateChanges = false;

    protected function requiresApprovalWhenTrait(array $modifications): bool
    {
        return $this->gateChanges;
    }

    protected function approvableItemGroups(array $dirtyKeys): array
    {
        return [
            'rules' => ['rules'],
            'notes' => ['notes'],
        ];
    }
}
