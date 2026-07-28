<?php

declare(strict_types=1);

namespace ReynoTECH\Approvals\Tests\Unit;

use ReynoTECH\Approvals\Models\Modification;
use ReynoTECH\Approvals\Tests\Fixtures\ApprovableModel;
use ReynoTECH\Approvals\Tests\Fixtures\ApproverModel;
use ReynoTECH\Approvals\Tests\Fixtures\DeleteWhenApprovedModel;
use ReynoTECH\Approvals\Tests\Fixtures\RestrictedApproverModel;
use ReynoTECH\Approvals\Tests\Fixtures\TwoApproverModel;
use ReynoTECH\Approvals\Tests\TestCase;

final class RequiresApprovalWhenChangesTest extends TestCase
{
    public function test_creating_a_new_model_never_goes_through_the_gate(): void
    {
        $model = new ApprovableModel(['name' => 'Fire Panel', 'status' => 'draft']);
        $model->gateChanges = true;

        $model->save();

        $this->assertTrue($model->exists);
        $this->assertNull(Modification::first());
    }

    public function test_a_model_can_require_more_than_one_approver(): void
    {
        $model = TwoApproverModel::create(['name' => 'Fire Panel', 'status' => 'draft']);
        $first = ApproverModel::create(['name' => 'Inspector A']);
        $second = ApproverModel::create(['name' => 'Inspector B']);

        $model->status = 'active';
        $model->save();

        $modification = Modification::first();
        $this->assertSame(2, $modification->approvers_required);

        $first->approve($modification);
        $this->assertSame('draft', $model->fresh()->status, 'one approval out of two must not apply the change yet');
        $this->assertSame(1, $modification->fresh()->approversRemaining);

        $second->approve($modification);
        $this->assertSame('active', $model->fresh()->status);
    }

    public function test_unauthorized_approver_cannot_cast_a_vote(): void
    {
        $model = ApprovableModel::create(['name' => 'Fire Panel', 'status' => 'draft']);
        $approver = RestrictedApproverModel::create(['name' => 'Guest']);

        $model->gateChanges = true;
        $model->status = 'active';
        $model->save();

        $modification = Modification::first();

        $this->assertFalse($approver->approve($modification));
        $this->assertFalse($approver->disapprove($modification));
        $this->assertSame(0, $modification->fresh()->approvals()->count());
    }

    public function test_delete_when_approved_removes_the_modification_row(): void
    {
        $model = DeleteWhenApprovedModel::create(['name' => 'Fire Panel', 'status' => 'draft']);
        $model->status = 'active';
        $model->save();

        $modification = Modification::first();
        $approver = ApproverModel::create(['name' => 'Inspector']);
        $approver->approve($modification);

        $this->assertSame('active', $model->fresh()->status);
        $this->assertNull(Modification::find($modification->id));
    }
}
