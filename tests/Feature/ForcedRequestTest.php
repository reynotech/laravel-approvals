<?php

declare(strict_types=1);

namespace ReynoTECH\Approvals\Tests\Feature;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use ReynoTECH\Approvals\Concerns\RequiresApprovalWhenChanges;
use ReynoTECH\Approvals\Contracts\Approvable;
use ReynoTECH\Approvals\Models\Approval;
use ReynoTECH\Approvals\Models\Disapproval;
use ReynoTECH\Approvals\Models\Modification;
use ReynoTECH\Approvals\Tests\Fixtures\ApproverModel;
use ReynoTECH\Approvals\Tests\TestCase;

class ForcedRequestModel extends Model
{
    use RequiresApprovalWhenChanges;

    public static bool $veto = false;

    public array $noTrackModification = ['notes'];

    protected $table = 'regression_models';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::saving(fn () => self::$veto ? false : null);
    }
}

class ForcedTwoApproversModel extends ForcedRequestModel
{
    protected function approversRequired(): int
    {
        return 2;
    }
}

class ForcedGroupedModel extends ForcedRequestModel
{
    protected function approvableItemGroups(array $dirtyKeys): array
    {
        return ['name' => ['name']];
    }
}

/**
 * An Approvable with no trait, whose decision may or may not close the Modification.
 */
class ManualApprovableModel extends Model implements Approvable
{
    public static bool $closes = true;

    protected $table = 'regression_models';

    protected $guarded = ['id'];

    public function modifications(): MorphMany
    {
        return $this->morphMany(Modification::class, 'modifiable');
    }

    public function applyModificationChanges(Modification $modification, bool $approved): void
    {
        $this->update(['status' => $approved ? 'effect of approving' : 'effect of rejecting']);

        if (self::$closes) {
            $modification->status = $approved ? Modification::STATUS_APPROVED : Modification::STATUS_REJECTED;
            $modification->save();
        }
    }
}

final class ForcedRequestTest extends TestCase
{
    protected function tearDown(): void
    {
        ForcedRequestModel::$veto = false;
        ManualApprovableModel::$closes = true;
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @param  class-string<ForcedRequestModel>  $class
     */
    private function stored(string $class = ForcedRequestModel::class): ForcedRequestModel
    {
        $created = $class::create(['name' => 'stored', 'status' => 'open', 'notes' => 'kept']);

        return $class::find($created->id);
    }

    /**
     * @param  class-string<ForcedRequestModel>  $class
     */
    private function hold(string $class = ForcedRequestModel::class, array $meta = ['reason' => 'new photo']): Modification
    {
        $model = $this->stored($class);

        $this->assertFalse($model->requiresApproval(fn () => true)->withModificationMeta($meta)->forceModification()->save());

        return $model->getLastModification();
    }

    private function user(string $name = 'Inspector'): ApproverModel
    {
        return ApproverModel::create(['name' => $name]);
    }

    private function row(int $id): array
    {
        return (array) Capsule::table('regression_models')->where('id', $id)->first();
    }

    private function pending(): int
    {
        return Modification::where('flow', 'approval')->where('status', 'pending')->count();
    }

    // 1. Held

    public function test_a_forced_gated_save_with_nothing_dirty_is_held_with_its_meta(): void
    {
        $request = $this->hold();

        $this->assertNotNull($request);
        $this->assertSame('approval', $request->flow);
        $this->assertSame('pending', $request->status);
        $this->assertTrue($request->isPending());

        $stored = $request->fresh();

        $this->assertSame(['reason' => 'new photo'], $stored->meta->toArray());
        $this->assertSame([], $stored->modifications->toArray());
        $this->assertSame(ForcedRequestModel::class, $stored->modifiable_type);
        $this->assertSame(1, $this->pending());
    }

    public function test_the_gate_method_holds_a_forced_save_like_the_callback(): void
    {
        $model = new class () extends ForcedRequestModel {
            protected function requiresApprovalWhenTrait(array $modifications): bool
            {
                return true;
            }
        };

        $model = $model->newQuery()->find($this->stored()->id);

        $this->assertFalse($model->forceModification()->save());
        $this->assertSame('pending', $model->getLastModification()->status);
    }

    public function test_a_forced_gated_save_with_changes_holds_only_those_changes(): void
    {
        $model = $this->stored();
        $model->name = 'proposed';

        $this->assertFalse($model->requiresApproval(fn () => true)->forceModification()->save());
        $this->assertSame(['name'], array_keys($model->getLastModification()->fresh()->modifications->toArray()));
        $this->assertSame('stored', $this->row($model->id)['name']);
        $this->assertSame(1, Modification::count());
    }

    public function test_a_forced_gated_save_holds_an_untracked_column_instead_of_losing_it(): void
    {
        $model = $this->stored();
        $model->notes = 'proposed';

        $this->assertFalse($model->requiresApproval(fn () => true)->forceModification()->save());
        $this->assertSame('kept', $this->row($model->id)['notes']);

        $this->assertTrue($this->user()->approve($model->getLastModification()));
        $this->assertSame('proposed', $this->row($model->id)['notes']);
    }

    public function test_a_forced_gated_touch_is_held_with_nothing_to_write(): void
    {
        Carbon::setTestNow('2026-01-01 10:00:00');
        $model = $this->stored();
        $before = $this->row($model->id);

        Carbon::setTestNow('2026-01-06 15:30:00');

        $this->assertFalse($model->requiresApproval(fn () => true)->forceModification()->touch());
        $this->assertSame([], $model->getLastModification()->fresh()->modifications->toArray());
        $this->assertSame($before, $this->row($model->id));
    }

    // 2. Approved

    public function test_approving_closes_it_writing_no_column_and_keeps_the_vote(): void
    {
        Carbon::setTestNow('2026-01-01 10:00:00');
        $request = $this->hold();
        $id = $request->modifiable_id;
        $before = $this->row($id);

        Carbon::setTestNow('2026-01-06 15:30:00');

        $this->assertTrue($this->user()->approve($request));
        $this->assertSame('approved', $request->fresh()->status);
        $this->assertSame(1, Approval::count());
        $this->assertSame($before, $this->row($id));
    }

    public function test_it_stays_pending_until_every_approver_voted(): void
    {
        $request = $this->hold(ForcedTwoApproversModel::class);

        $this->assertTrue($this->user('one')->approve($request));
        $this->assertSame('pending', $request->fresh()->status);

        $this->assertTrue($this->user('two')->approve($request));
        $this->assertSame('approved', $request->fresh()->status);
        $this->assertSame(2, Approval::count());
    }

    public function test_a_model_with_item_groups_is_decided_as_a_whole(): void
    {
        $request = $this->hold(ForcedGroupedModel::class);

        $this->assertSame(0, $request->items()->count());
        $this->assertTrue($this->user()->approve($request));
        $this->assertSame('approved', $request->fresh()->status);
    }

    public function test_forcing_the_approval_closes_it_with_no_vote(): void
    {
        $request = $this->hold();

        $request->fresh()->forceApprovalUpdate();

        $this->assertSame('approved', $request->fresh()->status);
        $this->assertSame(0, Approval::count());
    }

    public function test_a_veto_on_approval_stays_pending_and_undoes_the_vote(): void
    {
        $request = $this->hold();
        ForcedRequestModel::$veto = true;

        $this->assertFalse($this->user()->approve($request));
        $this->assertSame('pending', $request->fresh()->status);
        $this->assertSame(0, Approval::count());
    }

    // 3. Rejected

    public function test_rejecting_closes_it_writing_no_column_and_keeps_the_vote(): void
    {
        $request = $this->hold();
        $before = $this->row($request->modifiable_id);

        $this->assertTrue($this->user()->disapprove($request));
        $this->assertSame('rejected', $request->fresh()->status);
        $this->assertSame(1, Disapproval::count());
        $this->assertSame($before, $this->row($request->modifiable_id));
    }

    // 4. One save only

    public function test_force_doesnt_hold_the_next_save_too(): void
    {
        $model = $this->stored();
        $model->requiresApproval(fn () => true)->forceModification()->save();
        $request = $model->getLastModification();

        $this->assertTrue($model->save());
        $this->assertSame(1, Modification::count());

        $this->user()->approve($request);

        $this->assertTrue($model->save());
        $this->assertSame(1, Modification::count());
    }

    public function test_force_doesnt_audit_the_next_direct_save_too(): void
    {
        $model = $this->stored();
        $model->forceModification()->save();
        $this->assertSame(1, Modification::count());

        $this->assertTrue($model->save());
        $this->assertSame(1, Modification::count());
    }

    public function test_force_on_a_creation_doesnt_reach_the_next_save(): void
    {
        $model = new ForcedRequestModel(['name' => 'new', 'status' => 'open']);
        $model->forceModification()->save();
        $count = Modification::count();

        $this->assertTrue($model->save());
        $this->assertSame($count, Modification::count());
    }

    // 5. Unchanged: gate with no force

    public function test_a_gated_save_with_no_changes_and_no_force_saves_directly(): void
    {
        $model = $this->stored();

        $this->assertTrue($model->requiresApproval(fn () => true)->save());
        $this->assertSame(0, Modification::count());
    }

    public function test_a_gated_touch_with_no_force_saves_directly(): void
    {
        Carbon::setTestNow('2026-01-01 10:00:00');
        $model = $this->stored();

        Carbon::setTestNow('2026-01-06 15:30:00');

        $this->assertTrue($model->requiresApproval(fn () => true)->touch());
        $this->assertSame(0, $this->pending());
        $this->assertStringStartsWith('2026-01-06 15:30:00', (string) $this->row($model->id)['updated_at']);
    }

    public function test_a_gated_save_of_only_an_untracked_column_with_no_force_saves_directly(): void
    {
        $model = $this->stored();
        $model->notes = 'changed';

        $this->assertTrue($model->requiresApproval(fn () => true)->save());
        $this->assertSame(0, $this->pending());
        $this->assertSame('changed', $this->row($model->id)['notes']);
    }

    // 6. Unchanged: force with no gate

    public function test_force_with_no_gate_leaves_an_applied_audit_row(): void
    {
        $model = $this->stored();

        $this->assertTrue($model->withModificationMeta(['reason' => 'new photo'])->forceModification()->save());

        $audit = $model->getLastModification()->fresh();

        $this->assertSame('direct', $audit->flow);
        $this->assertSame('applied', $audit->status);
        $this->assertSame([], $audit->modifications->toArray());
        $this->assertSame(['reason' => 'new photo'], $audit->meta->toArray());
    }

    public function test_force_with_the_gate_answering_false_leaves_an_applied_audit_row(): void
    {
        $model = $this->stored();

        $this->assertTrue($model->requiresApproval(fn () => false)->forceModification()->save());
        $this->assertSame('applied', $model->getLastModification()->status);
    }

    // 7. What repeating an empty request does today: see the upgrade notes

    public function test_a_second_empty_request_is_the_first_one_while_that_stays_pending(): void
    {
        $model = $this->stored();
        $model->requiresApproval(fn () => true);

        $model->withModificationMeta(['reason' => 'first photo'])->forceModification()->save();
        $first = $model->getLastModification();

        $this->assertFalse($model->withModificationMeta(['reason' => 'second photo'])->forceModification()->save());

        $this->assertTrue($first->wasRecentlyCreated);
        $this->assertFalse($model->getLastModification()->wasRecentlyCreated);
        $this->assertTrue($first->is($model->getLastModification()));
        $this->assertSame(1, Modification::count());
        $this->assertSame(['reason' => 'first photo'], $first->fresh()->meta->toArray());
    }

    public function test_a_repeated_change_with_a_diff_also_says_it_was_already_pending(): void
    {
        $model = $this->stored();
        $model->requiresApproval(fn () => true);

        $model->name = 'proposed';
        $model->save();
        $this->assertTrue($model->getLastModification()->wasRecentlyCreated);

        $model->name = 'proposed';
        $model->save();
        $this->assertFalse($model->getLastModification()->wasRecentlyCreated);
        $this->assertSame(1, Modification::count());
    }

    public function test_the_check_in_the_docs_finds_a_pending_empty_request(): void
    {
        $model = $this->stored();
        $this->assertFalse($model->modifications()->activeOnly()->exists());

        $model->requiresApproval(fn () => true)->forceModification()->save();
        $this->assertTrue($model->newQuery()->lockForUpdate()->find($model->id)->modifications()->activeOnly()->exists());

        $this->user()->approve($model->getLastModification());
        $this->assertFalse($model->modifications()->activeOnly()->exists());
    }

    public function test_an_empty_request_after_the_first_was_decided_is_a_new_one(): void
    {
        $model = $this->stored();
        $model->requiresApproval(fn () => true);

        $model->forceModification()->save();
        $first = $model->getLastModification();
        $this->user()->approve($first);

        $this->assertFalse($model->forceModification()->save());

        $this->assertFalse($first->is($model->getLastModification()));
        $this->assertSame('pending', $model->getLastModification()->status);
        $this->assertSame(2, Modification::count());
    }

    // A manual Approvable

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function decisions(): array
    {
        return [
            'approve' => ['approve', 'approved', 'effect of approving'],
            'disapprove' => ['disapprove', 'rejected', 'effect of rejecting'],
        ];
    }

    private function manualRequest(): Modification
    {
        $model = ManualApprovableModel::create(['name' => 'manual', 'status' => 'open']);

        return $model->modifications()->create([
            'flow' => 'approval',
            'status' => 'pending',
            'is_update' => true,
            'approvers_required' => 1,
            'disapprovers_required' => 1,
            'md5' => md5('manual'),
            'modifications' => [],
        ]);
    }

    /**
     * @dataProvider decisions
     */
    public function test_a_manual_approvable_that_closes_the_modification_keeps_the_decision(string $method, string $status, string $effect): void
    {
        $request = $this->manualRequest();

        $this->assertTrue($this->user()->{$method}($request));
        $this->assertSame($status, $request->fresh()->status);
        $this->assertSame($effect, $this->row($request->modifiable_id)['status']);
        $this->assertSame(1, Approval::count() + Disapproval::count());
    }

    /**
     * @dataProvider decisions
     */
    public function test_a_manual_approvable_that_doesnt_close_it_loses_the_vote_and_its_effects(string $method): void
    {
        ManualApprovableModel::$closes = false;
        $request = $this->manualRequest();

        $this->assertFalse($this->user()->{$method}($request));
        $this->assertSame('pending', $request->fresh()->status);
        $this->assertSame('open', $this->row($request->modifiable_id)['status']);
        $this->assertSame(0, Approval::count() + Disapproval::count());
    }
}
