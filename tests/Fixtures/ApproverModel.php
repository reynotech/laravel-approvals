<?php

declare(strict_types=1);

namespace ReynoTECH\Approvals\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use ReynoTECH\Approvals\Concerns\ApprovesChanges;
use ReynoTECH\Approvals\Contracts\Approver;

class ApproverModel extends Model implements Approver
{
    use ApprovesChanges;

    protected $table = 'approver_models';

    protected $guarded = ['id'];
}
