<?php

declare(strict_types=1);

namespace ReynoTECH\Approvals\Tests\Fixtures;

use ReynoTECH\Approvals\Concerns\RequiresApprovalWhenChanges;
use ReynoTECH\Approvals\Contracts\Approvable;
use Illuminate\Database\Eloquent\Model;

/**
 * Same shape as ApprovableModel but requires 2 approvers, to exercise
 * approvers_required > 1.
 */
class TwoApproverModel extends Model implements Approvable
{
    use RequiresApprovalWhenChanges;

    protected $table = 'approvable_models';

    protected $guarded = ['id'];

    public bool $gateChanges = true;

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->approversRequired = 2;
    }

    protected function requiresApprovalWhenTrait(array $modifications): bool
    {
        return $this->gateChanges;
    }
}
