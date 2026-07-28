<?php

declare(strict_types=1);

namespace ReynoTECH\Approvals\Tests\Fixtures;

use ReynoTECH\Approvals\Models\Modification;

/**
 * An approver that is never authorized — exercises authorizedToApprove()/
 * authorizedToDisapprove() as an override point.
 */
class RestrictedApproverModel extends ApproverModel
{
    protected $table = 'approver_models';

    protected function authorizedToApprove(Modification $modification): bool
    {
        return false;
    }

    protected function authorizedToDisapprove(Modification $modification): bool
    {
        return false;
    }
}
