<?php

declare(strict_types=1);

namespace ReynoTECH\Approvals\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use ReynoTECH\Approvals\Concerns\RequiresApprovalWhenChanges;
use ReynoTECH\Approvals\Contracts\Approvable;

class DeleteWhenApprovedModel extends Model implements Approvable
{
    use RequiresApprovalWhenChanges;

    protected $table = 'approvable_models';

    protected $guarded = ['id'];

    public bool $gateChanges = true;

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->deleteWhenApproved = true;
    }

    protected function requiresApprovalWhenTrait(array $modifications): bool
    {
        return $this->gateChanges;
    }
}
