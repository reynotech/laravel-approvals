<?php

declare(strict_types=1);

namespace ReynoTECH\Approvals\Tests\Feature;

use ReynoTECH\Approvals\Models\Modification;
use ReynoTECH\Approvals\Models\ModificationItem;
use ReynoTECH\Approvals\Tests\Fixtures\ApprovableModel;
use ReynoTECH\Approvals\Tests\Fixtures\ApproverModel;
use ReynoTECH\Approvals\Tests\Fixtures\GroupedApprovableModel;
use ReynoTECH\Approvals\Tests\TestCase;

final class ModificationItemWorkflowTest extends TestCase
{
    public function test_direct_change_creates_a_direct_applied_modification_without_items(): void
    {
        $model = ApprovableModel::create(['name' => 'Fire Panel', 'status' => 'draft']);

        $model->status = 'active';
        $model->save();

        $modification = Modification::first();

        $this->assertSame('direct', $modification->flow);
        $this->assertSame('applied', $modification->status);
        $this->assertFalse($modification->active);
        $this->assertFalse($modification->is_modification);
        $this->assertSame(0, $modification->items()->count());
        $this->assertDiffSame(['status' => ['ori' => 'draft', 'mod' => 'active']], $modification->modifications->toArray());
    }

    public function test_pending_change_without_groups_creates_no_items_and_does_not_persist(): void
    {
        $model = ApprovableModel::create(['name' => 'Fire Panel', 'status' => 'draft']);

        $model->gateChanges = true;
        $model->status = 'active';
        $model->save();

        $this->assertSame('draft', $model->fresh()->status);

        $modification = Modification::first();

        $this->assertSame('approval', $modification->flow);
        $this->assertSame('pending', $modification->status);
        $this->assertTrue($modification->active);
        $this->assertTrue($modification->is_modification);
        $this->assertSame(0, $modification->items()->count());
    }

    public function test_pending_change_with_groups_creates_a_pending_item_per_group(): void
    {
        $model = GroupedApprovableModel::create(['name' => 'Fire Panel', 'rules' => 'r1', 'notes' => 'n1']);

        $model->gateChanges = true;
        $model->rules = 'r2';
        $model->notes = 'n2';
        $model->save();

        $this->assertSame('r1', $model->fresh()->rules, 'nothing should be persisted until an item is approved');
        $this->assertSame('n1', $model->fresh()->notes);

        $modification = Modification::first();

        $this->assertSame('approval', $modification->flow);
        $this->assertSame('pending', $modification->status);
        $this->assertSame(2, $modification->items()->count());

        $rulesItem = $modification->items()->where('key', 'rules')->first();
        $notesItem = $modification->items()->where('key', 'notes')->first();

        $this->assertSame('pending', $rulesItem->status);
        $this->assertSame(['rules' => 'r1'], $rulesItem->original->toArray());
        $this->assertSame(['rules' => 'r2'], $rulesItem->proposed->toArray());

        $this->assertSame('pending', $notesItem->status);
        $this->assertSame(['notes' => 'n1'], $notesItem->original->toArray());
        $this->assertSame(['notes' => 'n2'], $notesItem->proposed->toArray());
    }

    public function test_approving_one_group_and_rejecting_the_other_leaves_the_modification_partially_resolved(): void
    {
        $model = GroupedApprovableModel::create(['name' => 'Fire Panel', 'rules' => 'r1', 'notes' => 'n1']);
        $approver = ApproverModel::create(['name' => 'Inspector']);

        $model->gateChanges = true;
        $model->rules = 'r2';
        $model->notes = 'n2';
        $model->save();

        $modification = Modification::first();
        $rulesItem = $modification->items()->where('key', 'rules')->first();
        $notesItem = $modification->items()->where('key', 'notes')->first();

        $this->assertTrue($approver->approveItem($rulesItem, 'rules look right'));
        $this->assertTrue($approver->disapproveItem($notesItem, 'notes are wrong'));

        $fresh = $model->fresh();
        $this->assertSame('r2', $fresh->rules, 'the approved item must be applied');
        $this->assertSame('n1', $fresh->notes, 'the rejected item must not be applied');

        $this->assertSame('applied', $rulesItem->fresh()->status);
        $this->assertSame('rejected', $notesItem->fresh()->status);
        $this->assertSame('partially_resolved', $modification->fresh()->status);
        $this->assertFalse($modification->fresh()->active);
        $this->assertTrue($modification->fresh()->is_modification);
    }

    public function test_global_approval_on_a_modification_with_items_resolves_all_pending_items(): void
    {
        $model = GroupedApprovableModel::create(['name' => 'Fire Panel', 'rules' => 'r1', 'notes' => 'n1']);
        $approver = ApproverModel::create(['name' => 'Inspector']);

        $model->gateChanges = true;
        $model->rules = 'r2';
        $model->notes = 'n2';
        $model->save();

        $modification = Modification::first();

        $this->assertTrue($approver->approve($modification));

        $fresh = $model->fresh();
        $this->assertSame('r2', $fresh->rules);
        $this->assertSame('n2', $fresh->notes);
        $this->assertSame('approved', $modification->fresh()->status);

        foreach ($modification->items as $item) {
            $this->assertSame('applied', $item->fresh()->status);
        }
    }

    public function test_global_disapproval_on_a_modification_with_items_rejects_all_pending_items(): void
    {
        $model = GroupedApprovableModel::create(['name' => 'Fire Panel', 'rules' => 'r1', 'notes' => 'n1']);
        $approver = ApproverModel::create(['name' => 'Inspector']);

        $model->gateChanges = true;
        $model->rules = 'r2';
        $model->notes = 'n2';
        $model->save();

        $modification = Modification::first();

        $this->assertTrue($approver->disapprove($modification));

        $fresh = $model->fresh();
        $this->assertSame('r1', $fresh->rules);
        $this->assertSame('n1', $fresh->notes);
        $this->assertSame('rejected', $modification->fresh()->status);
    }

    public function test_duplicate_vote_and_changed_vote_on_an_item_are_handled_like_the_global_flow(): void
    {
        $model = GroupedApprovableModel::create(['name' => 'Fire Panel', 'rules' => 'r1', 'notes' => 'n1']);
        $approver = ApproverModel::create(['name' => 'Inspector']);

        $model->gateChanges = true;
        $model->rules = 'r2';
        $model->save();

        $modification = Modification::first();
        $rulesItem = $modification->items()->where('key', 'rules')->first();
        $rulesItem->update(['disapprovers_required' => 2]);

        $approver->disapproveItem($rulesItem, 'not yet');
        $approver->disapproveItem($rulesItem, 'not yet');
        $this->assertSame(1, $approver->disapprovals()->count());
        $this->assertSame('pending', $rulesItem->fresh()->status);
        $this->assertSame('r1', $model->fresh()->rules);

        $approver->approveItem($rulesItem, 'changed my mind');
        $this->assertSame(0, $approver->disapprovals()->count());
        $this->assertSame(1, $approver->approvals()->count());
        $this->assertSame('applied', $rulesItem->fresh()->status);
        $this->assertSame('r2', $model->fresh()->rules);
    }

    public function test_record_modification_captures_a_manual_event_without_a_diff(): void
    {
        $model = ApprovableModel::create(['name' => 'Fire Panel', 'status' => 'draft']);

        $modification = $model->recordModification(
            eventType: 'attachment_added',
            items: [
                [
                    'key' => 'attachments.contract',
                    'original' => null,
                    'proposed' => ['id' => 123, 'name' => 'contract.pdf'],
                ],
            ],
            context: ['label' => 'Contrato'],
        );

        $this->assertSame('attachment_added', $modification->event_type);
        $this->assertSame('direct', $modification->flow);
        $this->assertSame('applied', $modification->status);
        $this->assertSame(['label' => 'Contrato'], $modification->context->toArray());
        $this->assertSame(
            ['id' => 123, 'name' => 'contract.pdf'],
            $modification->modifications['attachments.contract']['mod']
        );
    }

    public function test_operation_id_is_shared_across_modifications_from_the_same_operation(): void
    {
        $model = ApprovableModel::create(['name' => 'Fire Panel', 'status' => 'draft']);

        $model->withOperationId('op-123');
        $model->gateChanges = true;
        $model->status = 'active';
        $model->save();

        $pending = Modification::first();
        $this->assertSame('op-123', $pending->operation_id);

        $model->withOperationId('op-123');
        $manual = $model->recordModification(eventType: 'note_added', items: [
            ['key' => 'note', 'original' => null, 'proposed' => 'hello'],
        ]);

        $this->assertSame('op-123', $manual->operation_id);
    }
}
