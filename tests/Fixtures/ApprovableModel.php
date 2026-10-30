<?php

declare(strict_types=1);

namespace ReynoTECH\Approvals\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use ReynoTECH\Approvals\Concerns\RequiresApprovalWhenChanges;
use ReynoTECH\Approvals\Contracts\Approvable;

class ApprovableModel extends Model implements Approvable
{
    use RequiresApprovalWhenChanges;

    protected $table = 'approvable_models';

    protected $guarded = ['id'];

    protected $casts = ['rules' => 'array'];

    /** Toggled by tests to force the approval-gating branch on. */
    public bool $gateChanges = false;

    protected function requiresApprovalWhenTrait(array $modifications): bool
    {
        return $this->gateChanges;
    }
}
