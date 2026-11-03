<?php

declare(strict_types=1);

namespace ReynoTECH\Approvals\Tests\Feature;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\QueryException;
use ReynoTECH\Approvals\Concerns\RequiresApprovalWhenChanges;
use ReynoTECH\Approvals\Models\Approval;
use ReynoTECH\Approvals\Models\Disapproval;
use ReynoTECH\Approvals\Models\Modification;
use ReynoTECH\Approvals\Models\ModificationItem;
use ReynoTECH\Approvals\Tests\Fixtures\ApproverModel;
use ReynoTECH\Approvals\Tests\Fixtures\DeleteWhenApprovedModel;
use ReynoTECH\Approvals\Tests\Fixtures\TwoApproverModel;
use ReynoTECH\Approvals\Tests\TestCase;

class DecisionModel extends Model
{
    use RequiresApprovalWhenChanges;

    protected $table = 'regression_models';

    protected $guarded = ['id'];

    public bool $gate = false;

    protected function requiresApprovalWhenTrait(array $modifications): bool
    {
        return $this->gate;
    }
}

class SoftDeletingDecisionModel extends DecisionModel
{
    use SoftDeletes;
}

class FullyGroupedModel extends DecisionModel
{
    protected function approvableItemGroups(array $dirtyKeys): array
    {
        return ['notes' => ['notes'], 'status' => ['status']];
    }
}

class PartlyGroupedModel extends DecisionModel
{
    protected function approvableItemGroups(array $dirtyKeys): array
    {
        return ['notes' => ['notes']];
    }
}

class VetoingModel extends DecisionModel
{
    public static bool $veto = false;

    protected static function booted(): void
    {
        static::updating(fn () => self::$veto ? false : null);
    }
}

class VetoingGroupedModel extends VetoingModel
{
    protected function approvableItemGroups(array $dirtyKeys): array
    {
        return ['status' => ['status']];
    }
}

class ThrowingModel extends DecisionModel
{
    public static bool $boom = false;

    protected static function booted(): void
    {
        static::updating(function () {
            if (self::$boom) {
                throw new \RuntimeException('boom');
            }
        });
    }
}

class NoUpdateModel extends DecisionModel
{
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->updateWhenApproved = false;
    }
}

class ConstructorGatedModel extends DecisionModel
{
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->requiresApproval(fn () => true);
    }
}

class ThreeApproverModel extends DecisionModel
{
    protected function approversRequired(): int
    {
        return 3;
    }
}

final class ApprovalDecisionTest extends TestCase
{
    protected function tearDown(): void
    {
        Relation::$morphMap = [];
        VetoingModel::$veto = false;
        ThrowingModel::$boom = false;

        parent::tearDown();
    }

    private function user(string $name = 'Inspector'): ApproverModel
    {
        return ApproverModel::create(['name' => $name]);
    }

    private function gated(DecisionModel $model, array $changes): Modification
    {
        $model->gate = true;
        $model->forceFill($changes)->save();
        $model->gate = false;

        return $model->getLastModification();
    }

    public function test_an_approver_holds_one_vote_whatever_the_reason(): void
    {
        $model = TwoApproverModel::create(['name' => 'n', 'status' => 'draft']);
        $model->status = 'live';
        $model->save();
        $modification = $model->getLastModification();
        $approver = $this->user();

        $approver->approve($modification, 'first');
        $approver->approve($modification, 'second');

        $this->assertSame('draft', $model->fresh()->status);
        $this->assertSame(1, Approval::count());
        $this->assertSame('first', Approval::first()->reason);
    }

    public function test_switching_a_vote_removes_every_earlier_one(): void
    {
        $model = TwoApproverModel::create(['name' => 'n', 'status' => 'draft']);
        $model->status = 'live';
        $model->save();
        $modification = $model->getLastModification();
        $modification->update(['disapprovers_required' => 5]);
        $approver = $this->user();

        // As left in the database by versions that keyed votes by reason.
        foreach (['a', 'b'] as $reason) {
            Approval::create([
                'modification_id' => $modification->id,
                'approver_id' => $approver->id,
                'approver_type' => ApproverModel::class,
                'reason' => $reason,
            ]);
        }

        $approver->disapprove($modification);

        $this->assertSame(0, Approval::count());
        $this->assertSame(1, Disapproval::count());
    }

    public function test_an_approved_change_takes_no_more_votes(): void
    {
        $model = DecisionModel::create(['name' => 'n', 'status' => 'draft']);
        $modification = $this->gated($model, ['status' => 'one']);
        $this->assertTrue($this->user('a')->approve($modification));

        $newer = DecisionModel::find($model->id);
        $newer->status = 'two';
        $newer->save();

        $this->assertFalse($this->user('b')->approve($modification));
        $this->assertFalse($this->user('c')->disapprove($modification));
        $this->assertSame('two', DecisionModel::find($model->id)->status);
        $this->assertSame('approved', $modification->fresh()->status);
    }

    public function test_a_rejection_is_final(): void
    {
        $model = DecisionModel::create(['name' => 'n', 'status' => 'draft']);
        $modification = $this->gated($model, ['status' => 'one']);
        $approver = $this->user();
        $approver->disapprove($modification);

        $this->assertFalse($approver->approve($modification));

        $this->assertSame('draft', DecisionModel::find($model->id)->status);
        $this->assertSame('rejected', $modification->fresh()->status);
    }

    public function test_a_direct_audit_row_takes_no_votes(): void
    {
        $model = DecisionModel::create(['name' => 'n', 'status' => 'draft']);
        $model->status = 'one';
        $model->save();
        $audit = $model->getLastModification();
        $model->status = 'two';
        $model->save();

        $this->assertFalse($this->user()->approve($audit));

        $this->assertSame('two', DecisionModel::find($model->id)->status);
        $this->assertSame('applied', $audit->fresh()->status);
    }

    public function test_a_decided_item_takes_no_more_votes(): void
    {
        $model = FullyGroupedModel::create(['name' => 'n', 'status' => 'draft']);
        $modification = $this->gated($model, ['status' => 'one']);
        $item = $modification->items()->first();
        $this->assertTrue($this->user('a')->approveItem($item));

        $newer = FullyGroupedModel::find($model->id);
        $newer->status = 'two';
        $newer->save();

        $this->assertFalse($this->user('b')->approveItem($item));
        $this->assertFalse($this->user('c')->disapproveItem($item));
        $this->assertSame('two', FullyGroupedModel::find($model->id)->status);
        $this->assertSame('applied', $item->fresh()->status);
    }

    public function test_approving_an_item_can_clear_a_field(): void
    {
        $model = FullyGroupedModel::create(['name' => 'n', 'notes' => 'a']);
        $modification = $this->gated($model, ['notes' => null]);

        $this->assertTrue($this->user()->approve($modification));

        $this->assertNull(FullyGroupedModel::find($model->id)->notes);
        $this->assertSame('approved', $modification->fresh()->status);
    }

    public function test_approving_again_after_the_row_was_deleted_returns_false(): void
    {
        $model = DeleteWhenApprovedModel::create(['name' => 'n', 'status' => 'draft']);
        $model->status = 'one';
        $model->save();
        $modification = $model->getLastModification();

        $this->assertTrue($this->user('a')->approve($modification));
        $this->assertFalse($this->user('b')->approve($modification));
    }

    /**
     * @return array<string, array{class-string<DecisionModel>}>
     */
    public static function deletions(): array
    {
        return ['hard delete' => [DecisionModel::class], 'soft delete' => [SoftDeletingDecisionModel::class]];
    }

    /**
     * @dataProvider deletions
     */
    public function test_a_change_to_a_deleted_model_cant_be_approved_but_can_be_rejected(string $class): void
    {
        $model = $class::create(['name' => 'n', 'status' => 'draft']);
        $modification = $this->gated($model, ['status' => 'one']);
        $model->delete();

        $this->assertFalse($this->user('a')->approve($modification));
        $this->assertSame(0, Approval::count());
        $this->assertSame('pending', $modification->fresh()->status);

        $this->assertTrue($this->user('b')->disapprove($modification));
        $this->assertSame('rejected', $modification->fresh()->status);
    }

    public function test_a_vetoed_save_keeps_the_change_pending(): void
    {
        $model = VetoingModel::create(['name' => 'n', 'status' => 'draft']);
        $modification = $this->gated($model, ['status' => 'one']);
        VetoingModel::$veto = true;
        $approver = $this->user();

        $this->assertFalse($approver->approve($modification));
        $this->assertSame('draft', VetoingModel::find($model->id)->status);
        $this->assertSame('pending', $modification->fresh()->status);

        VetoingModel::$veto = false;

        $this->assertTrue($approver->approve($modification));
        $this->assertSame('one', VetoingModel::find($model->id)->status);
    }

    public function test_a_vetoed_save_keeps_the_item_pending(): void
    {
        $model = VetoingGroupedModel::create(['name' => 'n', 'status' => 'draft']);
        $modification = $this->gated($model, ['status' => 'one']);
        VetoingModel::$veto = true;

        $this->assertFalse($this->user()->approve($modification));

        $this->assertSame('pending', $modification->items()->first()->status);
        $this->assertSame('pending', $modification->fresh()->status);
    }

    public function test_a_failed_apply_leaves_no_vote_behind(): void
    {
        $model = ThrowingModel::create(['name' => 'n', 'status' => 'draft']);
        $modification = $this->gated($model, ['status' => 'one']);
        ThrowingModel::$boom = true;

        try {
            $this->user()->approve($modification);
            $this->fail('the failure should reach the caller');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame(0, Approval::count());
        $this->assertSame('pending', $modification->fresh()->status);
    }

    public function test_update_when_approved_false_still_resolves_the_row(): void
    {
        $model = NoUpdateModel::create(['name' => 'n', 'status' => 'draft']);
        $modification = $this->gated($model, ['status' => 'one']);

        $this->assertTrue($this->user()->approve($modification));

        $this->assertSame('approved', $modification->fresh()->status);
        $this->assertSame('draft', NoUpdateModel::find($model->id)->status);
    }

    public function test_forcing_an_approval_resolves_its_items(): void
    {
        $model = FullyGroupedModel::create(['name' => 'n', 'status' => 'draft']);
        $modification = $this->gated($model, ['status' => 'one']);

        $modification->fresh()->forceApprovalUpdate();

        $this->assertSame('one', FullyGroupedModel::find($model->id)->status);
        $this->assertSame(0, $modification->items()->pending()->count());
    }

    public function test_item_votes_dont_count_against_the_modification_quota(): void
    {
        $model = FullyGroupedModel::create(['name' => 'n', 'status' => 'draft', 'notes' => 'a']);
        $modification = $this->gated($model, ['status' => 'one', 'notes' => 'b']);

        $this->user()->approve($modification);

        $this->assertSame(1, $modification->fresh()->approversRemaining);
    }

    public function test_the_required_votes_can_be_overridden(): void
    {
        $model = ThreeApproverModel::create(['name' => 'n', 'status' => 'draft']);

        $this->assertSame(3, $this->gated($model, ['status' => 'one'])->approvers_required);
    }

    public function test_fields_outside_every_group_are_applied_by_a_global_approval(): void
    {
        $model = PartlyGroupedModel::create(['name' => 'n', 'status' => 'draft', 'notes' => 'a']);
        $modification = $this->gated($model, ['status' => 'one', 'notes' => 'b']);

        $this->assertTrue($this->user()->approve($modification));

        $fresh = PartlyGroupedModel::find($model->id);
        $this->assertSame('b', $fresh->notes);
        $this->assertSame('one', $fresh->status);
        $this->assertSame('approved', $modification->fresh()->status);
    }

    public function test_fields_outside_every_group_are_decided_on_their_own_item(): void
    {
        $model = PartlyGroupedModel::create(['name' => 'n', 'status' => 'draft', 'notes' => 'a']);
        $modification = $this->gated($model, ['status' => 'one', 'notes' => 'b']);
        $approver = $this->user();

        $approver->disapproveItem($modification->items()->where('key', ModificationItem::KEY_UNGROUPED)->first());
        $approver->approveItem($modification->items()->where('key', 'notes')->first());

        $fresh = PartlyGroupedModel::find($model->id);
        $this->assertSame('b', $fresh->notes);
        $this->assertSame('draft', $fresh->status);
        $this->assertSame('partially_resolved', $modification->fresh()->status);
    }

    public function test_changes_covered_by_groups_create_no_extra_item(): void
    {
        $model = PartlyGroupedModel::create(['name' => 'n', 'notes' => 'a']);
        $modification = $this->gated($model, ['notes' => 'b']);

        $this->assertSame(['notes'], $modification->items()->pluck('key')->all());
    }

    public function test_a_forced_update_doesnt_reopen_the_change_on_a_callback_gate(): void
    {
        $model = ConstructorGatedModel::find(ConstructorGatedModel::create(['name' => 'n', 'status' => 'draft'])->id);
        $model->status = 'one';
        $model->save();

        $this->user()->approve($model->getLastModification());

        $this->assertSame('one', ConstructorGatedModel::find($model->id)->status);
        $this->assertSame(1, Modification::count());
    }

    public function test_auditing_keeps_working_after_forcing_an_approval_on_the_same_instance(): void
    {
        $model = DecisionModel::create(['name' => 'n', 'status' => 'draft']);
        $modification = $this->gated($model, ['status' => 'one'])->fresh();
        $modification->forceApprovalUpdate();
        $instance = $modification->modifiable;

        $instance->status = 'two';
        $instance->save();

        $this->assertSame(2, Modification::count());
    }

    public function test_a_forced_update_covers_only_one_save(): void
    {
        $model = DecisionModel::find(DecisionModel::create(['name' => 'n', 'status' => 'draft'])->id);
        $model->gate = true;
        $model->setForcedApprovalUpdate(true);

        $model->status = 'one';
        $model->save();
        $model->status = 'two';
        $model->save();

        $this->assertSame('one', DecisionModel::find($model->id)->status);
        $this->assertSame('pending', $model->getLastModification()->status);
    }

    public function test_meta_attaches_only_to_the_next_modification(): void
    {
        $model = DecisionModel::find(DecisionModel::create(['name' => 'n'])->id);
        $model->withModificationMeta(['request' => 1]);

        $model->name = 'a';
        $model->save();
        $first = $model->getLastModification();
        $model->name = 'b';
        $model->save();
        $second = $model->getLastModification();

        $this->assertSame(['request' => 1], $first->fresh()->meta->toArray());
        $this->assertNull($second->fresh()->meta);
    }

    public function test_scalar_meta_can_be_read_back(): void
    {
        $model = DecisionModel::find(DecisionModel::create(['name' => 'n'])->id);
        $model->withModificationMeta('note');
        $model->name = 'a';
        $model->save();

        $this->assertSame(['note'], $model->getLastModification()->fresh()->meta->toArray());
    }

    public function test_a_vetoed_save_leaves_no_audit_row(): void
    {
        $model = VetoingModel::find(VetoingModel::create(['name' => 'n', 'status' => 'draft'])->id);
        VetoingModel::$veto = true;

        $model->status = 'one';
        $this->assertFalse($model->save());

        $this->assertSame(0, Modification::count());
    }

    public function test_a_save_the_database_refuses_leaves_no_audit_row(): void
    {
        DecisionModel::create(['name' => 'taken', 'code' => 'dup']);
        $model = DecisionModel::find(DecisionModel::create(['name' => 'n', 'code' => 'mine'])->id);
        $model->code = 'dup';

        try {
            $model->save();
            $this->fail('the unique index should refuse this');
        } catch (QueryException) {
        }

        $this->assertSame(0, Modification::count());
    }

    public function test_a_retried_save_records_one_audit_row(): void
    {
        $model = VetoingModel::find(VetoingModel::create(['name' => 'n', 'status' => 'draft'])->id);
        VetoingModel::$veto = true;
        $model->status = 'one';
        $model->save();

        VetoingModel::$veto = false;
        $this->assertTrue($model->save());

        $this->assertSame(1, Modification::count());
        $this->assertSame('one', $model->getLastModification()->modifications['status']['mod']);
    }

    public function test_creating_with_an_explicit_primary_key_skips_the_gate_and_the_audit(): void
    {
        $model = new DecisionModel();
        $model->gate = true;
        $model->id = 99;
        $model->name = 'seed';

        $this->assertTrue($model->save());

        $this->assertTrue(DecisionModel::whereKey(99)->exists());
        $this->assertSame(0, Modification::count());
    }

    public function test_votes_use_the_morph_map(): void
    {
        Relation::morphMap(['approver' => ApproverModel::class, 'decision' => DecisionModel::class]);
        $model = DecisionModel::create(['name' => 'n', 'status' => 'draft', 'notes' => 'a']);
        $approved = $this->gated($model, ['status' => 'one']);
        $rejected = $this->gated(DecisionModel::find($model->id), ['notes' => 'b']);
        $approver = $this->user();

        $approver->approve($approved);
        $approver->disapprove($rejected);

        $this->assertSame(1, $approver->approvals()->count());
        $this->assertSame(1, $approver->disapprovals()->count());
    }

    public function test_the_modifier_uses_the_morph_map(): void
    {
        Relation::morphMap(['approver' => ApproverModel::class, 'decision' => DecisionModel::class]);
        $user = $this->user();
        test_set_authenticated_user($user);
        $model = DecisionModel::find(DecisionModel::create(['name' => 'n'])->id);

        $model->name = 'x';
        $model->save();

        $modification = $model->getLastModification()->fresh();
        $this->assertSame('approver', $modification->modifier_type);
        $this->assertTrue($modification->modifier->is($user));
    }

    public function test_creations_use_the_morph_map(): void
    {
        Relation::morphMap(['decision' => DecisionModel::class]);
        $modification = DecisionModel::create(['name' => 'n'])->recordModification('created');
        $modification->update(['is_update' => false]);

        $this->assertCount(1, DecisionModel::creations());
    }

    public function test_rejecting_a_deleted_model_works_with_a_morph_map(): void
    {
        Relation::morphMap(['decision' => DecisionModel::class]);
        $model = DecisionModel::create(['name' => 'n', 'status' => 'draft']);
        $modification = $this->gated($model, ['status' => 'one']);
        Capsule::table('regression_models')->where('id', $model->id)->delete();

        $this->assertTrue($this->user()->disapprove($modification));
        $this->assertSame('rejected', $modification->fresh()->status);
    }
}
