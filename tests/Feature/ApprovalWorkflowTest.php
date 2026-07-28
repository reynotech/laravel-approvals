<?php

declare(strict_types=1);

namespace ReynoTECH\Approvals\Tests\Feature;

use ReynoTECH\Approvals\Models\Modification;
use ReynoTECH\Approvals\Tests\Fixtures\ApprovableModel;
use ReynoTECH\Approvals\Tests\Fixtures\ApproverModel;
use ReynoTECH\Approvals\Tests\TestCase;

final class ApprovalWorkflowTest extends TestCase
{
    public function test_update_without_gating_saves_directly_and_records_an_audit_modification(): void
    {
        $model = ApprovableModel::create(['name' => 'Fire Panel', 'status' => 'draft']);

        $model->status = 'active';
        $model->save();

        $this->assertSame('active', $model->fresh()->status);

        $audit = Modification::first();
        $this->assertNotNull($audit);
        $this->assertFalse($audit->active);
        $this->assertFalse($audit->is_modification);
    }

    public function test_update_with_gating_creates_a_pending_modification_instead_of_saving(): void
    {
        $model = ApprovableModel::create(['name' => 'Fire Panel', 'status' => 'draft']);

        $model->gateChanges = true;
        $model->status = 'active';
        $model->save();

        $this->assertSame('draft', $model->fresh()->status, 'the underlying row must not change until approved');

        $modification = Modification::first();
        $this->assertNotNull($modification);
        $this->assertTrue($modification->active);
        $this->assertTrue($modification->is_modification);
        $this->assertSame(1, $modification->approvers_required);
        $this->assertSame(['status' => ['ori' => 'draft', 'mod' => 'active']], $modification->modifications->toArray());
    }

    public function test_reaching_the_required_approvals_applies_the_change(): void
    {
        $model = ApprovableModel::create(['name' => 'Fire Panel', 'status' => 'draft']);
        $approver = ApproverModel::create(['name' => 'Inspector']);

        $model->gateChanges = true;
        $model->status = 'active';
        $model->save();

        $modification = Modification::first();

        $this->assertTrue($approver->approve($modification, 'looks correct'));
        $this->assertSame('active', $model->fresh()->status);
        $this->assertFalse($modification->fresh()->active);
    }

    public function test_reaching_the_required_disapprovals_leaves_the_row_untouched(): void
    {
        $model = ApprovableModel::create(['name' => 'Fire Panel', 'status' => 'draft']);
        $approver = ApproverModel::create(['name' => 'Inspector']);

        $model->gateChanges = true;
        $model->status = 'active';
        $model->save();

        $modification = Modification::first();

        $this->assertTrue($approver->disapprove($modification, 'not verified yet'));
        $this->assertSame('draft', $model->fresh()->status);
        $this->assertFalse($modification->fresh()->active);
    }

    public function test_approving_after_disapproving_switches_the_vote(): void
    {
        $model = ApprovableModel::create(['name' => 'Fire Panel', 'status' => 'draft']);
        $approver = ApproverModel::create(['name' => 'Inspector']);

        $model->gateChanges = true;
        $model->status = 'active';
        $model->save();

        $modification = Modification::first();

        $approver->disapprove($modification);
        $approver->approve($modification);

        $this->assertSame(0, $approver->disapprovals()->count());
        $this->assertSame(1, $approver->approvals()->count());
        $this->assertSame('active', $model->fresh()->status);
    }

    public function test_approve_call_is_idempotent(): void
    {
        $model = ApprovableModel::create(['name' => 'Fire Panel', 'status' => 'draft']);
        $approver = ApproverModel::create(['name' => 'Inspector']);

        $model->gateChanges = true;
        $model->status = 'active';
        $model->save();

        $modification = Modification::first();

        $approver->approve($modification, 'ok');
        $approver->approve($modification, 'ok');

        $this->assertSame(1, $approver->approvals()->count());
    }

    public function test_modifier_is_recorded_from_the_auth_helper(): void
    {
        $approver = ApproverModel::create(['name' => 'Inspector']);
        test_set_authenticated_user($approver);

        $model = ApprovableModel::create(['name' => 'Fire Panel', 'status' => 'draft']);
        $model->gateChanges = true;
        $model->status = 'active';
        $model->save();

        $modification = Modification::first();

        $this->assertSame($approver->id, $modification->modifier_id);
        $this->assertSame(ApproverModel::class, $modification->modifier_type);
    }
}
