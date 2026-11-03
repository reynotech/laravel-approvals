<?php

declare(strict_types=1);

namespace ReynoTECH\Approvals\Tests\Feature;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use ReynoTECH\Approvals\Concerns\RequiresApprovalWhenChanges;
use ReynoTECH\Approvals\Contracts\Approvable;
use ReynoTECH\Approvals\Contracts\GatesCreation;
use ReynoTECH\Approvals\Models\Approval;
use ReynoTECH\Approvals\Models\Modification;
use ReynoTECH\Approvals\Tests\Fixtures\ApproverModel;
use ReynoTECH\Approvals\Tests\TestCase;

class ConfiguredCreationModel extends Model implements Approvable, GatesCreation
{
    use RequiresApprovalWhenChanges;

    public static bool $veto = false;

    protected $table = 'regression_models';

    protected $guarded = ['id'];

    protected $casts = ['payload' => 'array', 'stamp' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(fn () => self::$veto ? false : null);
    }

    public function requiresApprovalOnCreate(array $attributes): bool
    {
        return ($attributes['status'] ?? null) === 'gated';
    }
}

class DeferredCreationModel extends ConfiguredCreationModel
{
    public function approvalCreationMode(): string
    {
        return GatesCreation::DEFERRED;
    }
}

/** On a table with no draft column: deferred mode must never need one. */
class DeferredNoColumnModel extends Model implements Approvable, GatesCreation
{
    use RequiresApprovalWhenChanges;

    protected $table = 'approvable_models';

    protected $guarded = ['id'];

    public function requiresApprovalOnCreate(array $attributes): bool
    {
        return true;
    }
}

class DeferredNoTimestampsModel extends DeferredCreationModel
{
    public $timestamps = false;
}

class DeferredNoCreatedAtModel extends DeferredCreationModel
{
    public const CREATED_AT = null;
}

class TwoApproverDeferredModel extends DeferredCreationModel
{
    protected function approversRequired(): int
    {
        return 2;
    }
}

class DraftCreationModel extends ConfiguredCreationModel
{
    public function approvalCreationMode(): string
    {
        return GatesCreation::DRAFT;
    }
}

class SoftDeletingDraftModel extends DraftCreationModel
{
    use SoftDeletes;
}

class TwoApproverDraftModel extends DraftCreationModel
{
    protected function approversRequired(): int
    {
        return 2;
    }
}

class UnknownModeModel extends ConfiguredCreationModel
{
    public function approvalCreationMode(): string
    {
        return 'later';
    }
}

final class CreationApprovalTest extends TestCase
{
    protected function tearDown(): void
    {
        Relation::$morphMap = [];
        ConfiguredCreationModel::$veto = false;
        Carbon::setTestNow();
        test_set_config('approvals.creation.mode', 'deferred');

        parent::tearDown();
    }

    private function user(string $name = 'Inspector'): ApproverModel
    {
        return ApproverModel::create(['name' => $name]);
    }

    private function rows(string $table = 'regression_models'): int
    {
        return Capsule::table($table)->count();
    }

    // Deferred mode

    public function test_deferred_holds_the_creation_without_a_row(): void
    {
        $model = DeferredCreationModel::create(['name' => 'ACME', 'status' => 'gated']);

        $this->assertFalse($model->exists);
        $this->assertNull($model->id);
        $this->assertSame(0, $this->rows());

        $request = $model->getLastModification()->fresh();
        $this->assertSame('pending', $request->status);
        $this->assertSame('approval', $request->flow);
        $this->assertFalse($request->is_update);
        $this->assertNull($request->modifiable_id);
        $this->assertSame(DeferredCreationModel::class, $request->modifiable_type);
        $this->assertDiffSame(['ori' => null, 'mod' => 'ACME'], $request->modifications['name']);
        $this->assertSame(0, $request->items()->count());
    }

    public function test_deferred_inserts_the_row_on_approval_and_links_it(): void
    {
        $request = DeferredCreationModel::create([
            'name' => 'ACME',
            'status' => 'gated',
            'payload' => ['a' => 1],
            'stamp' => '2026-02-02 11:00:00',
        ])->getLastModification();

        $this->assertTrue($this->user()->approve($request));

        $this->assertSame(1, $this->rows());
        $fresh = $request->fresh();
        $this->assertSame('approved', $fresh->status);
        $this->assertInstanceOf(DeferredCreationModel::class, $fresh->modifiable);
        $this->assertSame('ACME', $fresh->modifiable->name);
        $this->assertSame(['a' => 1], $fresh->modifiable->payload);
        $this->assertSame('2026-02-02 11:00:00', $fresh->modifiable->stamp->format('Y-m-d H:i:s'));
    }

    public function test_deferred_keeps_an_explicit_null(): void
    {
        $request = DeferredCreationModel::create(['name' => 'ACME', 'status' => 'gated', 'notes' => null])->getLastModification();

        $this->assertArrayHasKey('notes', $request->modifications->toArray());

        $this->user()->approve($request);
        $this->assertNull($request->fresh()->modifiable->notes);
    }

    public function test_deferred_rejection_leaves_nothing(): void
    {
        $request = DeferredCreationModel::create(['name' => 'ACME', 'status' => 'gated'])->getLastModification();

        $this->assertTrue($this->user()->disapprove($request));

        $this->assertSame(0, $this->rows());
        $this->assertSame('rejected', $request->fresh()->status);
    }

    public function test_deferred_waits_for_every_approver(): void
    {
        $request = TwoApproverDeferredModel::create(['name' => 'ACME', 'status' => 'gated'])->getLastModification();
        $this->assertSame(2, $request->approvers_required);

        $this->user('a')->approve($request);
        $this->assertSame(0, $this->rows());

        $this->user('b')->approve($request);
        $this->assertSame(1, $this->rows());
    }

    public function test_deferred_approved_twice_inserts_one_row(): void
    {
        $request = DeferredCreationModel::create(['name' => 'ACME', 'status' => 'gated'])->getLastModification();

        $this->assertTrue($this->user('a')->approve($request));
        $this->assertFalse($this->user('b')->approve($request));

        $this->assertSame(1, $this->rows());
    }

    public function test_deferred_repeats_of_a_request_keep_the_first_one(): void
    {
        $first = DeferredCreationModel::create(['name' => 'ACME', 'status' => 'gated']);
        $second = DeferredCreationModel::create(['name' => 'ACME', 'status' => 'gated']);
        $other = DeferredCreationModel::create(['name' => 'Other', 'status' => 'gated']);

        $this->assertSame(2, Modification::count());
        $this->assertTrue($first->getLastModification()->is($second->getLastModification()));
        $this->assertFalse($first->getLastModification()->is($other->getLastModification()));
    }

    public function test_deferred_can_repeat_a_request_once_the_first_was_decided(): void
    {
        $first = DeferredCreationModel::create(['name' => 'ACME', 'status' => 'gated'])->getLastModification();
        $this->user()->disapprove($first);

        $second = DeferredCreationModel::create(['name' => 'ACME', 'status' => 'gated'])->getLastModification();

        $this->assertFalse($first->is($second));
        $this->assertSame('pending', $second->status);
    }

    public function test_deferred_failure_on_approval_keeps_the_request_pending(): void
    {
        DeferredCreationModel::create(['name' => 'taken', 'code' => 'dup']);
        $request = DeferredCreationModel::create(['name' => 'ACME', 'status' => 'gated', 'code' => 'dup'])->getLastModification();

        try {
            $this->user()->approve($request);
            $this->fail('the unique index should refuse this');
        } catch (QueryException) {
        }

        $this->assertSame(0, Approval::count());
        $this->assertSame('pending', $request->fresh()->status);
        $this->assertNull($request->fresh()->modifiable_id);
    }

    public function test_deferred_failure_when_forcing_reaches_the_caller_and_keeps_it_pending(): void
    {
        DeferredCreationModel::create(['name' => 'taken', 'code' => 'dup']);
        $request = DeferredCreationModel::create(['name' => 'ACME', 'status' => 'gated', 'code' => 'dup'])->getLastModification();

        try {
            $request->fresh()->forceApprovalUpdate();
            $this->fail('the unique index should refuse this');
        } catch (QueryException) {
        }

        $this->assertSame('pending', $request->fresh()->status);
        $this->assertNull($request->fresh()->modifiable_id);
    }

    public function test_deferred_veto_on_approval_keeps_the_request_pending(): void
    {
        $request = DeferredCreationModel::create(['name' => 'ACME', 'status' => 'gated'])->getLastModification();
        ConfiguredCreationModel::$veto = true;

        $this->assertFalse($this->user()->approve($request));

        $this->assertSame(0, $this->rows());
        $this->assertSame('pending', $request->fresh()->status);
    }

    public function test_deferred_forcing_an_approval_inserts_the_row(): void
    {
        $request = DeferredCreationModel::create(['name' => 'ACME', 'status' => 'gated'])->getLastModification();

        $request->forceApprovalUpdate();

        $this->assertSame(1, $this->rows());
        $this->assertSame('approved', $request->fresh()->status);
    }

    public function test_deferred_needs_no_draft_column(): void
    {
        $request = DeferredNoColumnModel::create(['name' => 'ACME'])->getLastModification();
        $this->user()->approve($request);

        $this->assertSame(1, DeferredNoColumnModel::count());
        $this->assertSame('ACME', DeferredNoColumnModel::first()->name);
    }

    public function test_pending_creations_lists_only_undecided_ones_of_that_model(): void
    {
        $decided = DeferredCreationModel::create(['name' => 'a', 'status' => 'gated'])->getLastModification();
        $this->user()->disapprove($decided);
        $pending = DeferredCreationModel::create(['name' => 'b', 'status' => 'gated'])->getLastModification();
        DraftCreationModel::create(['name' => 'c', 'status' => 'gated']);

        $this->assertSame([$pending->id], DeferredCreationModel::pendingCreations()->pluck('id')->all());
    }

    public function test_deferred_works_with_a_morph_map(): void
    {
        Relation::morphMap(['client' => DeferredCreationModel::class]);
        $request = DeferredCreationModel::create(['name' => 'ACME', 'status' => 'gated'])->getLastModification();

        $this->assertSame('client', $request->modifiable_type);
        $this->assertCount(1, DeferredCreationModel::pendingCreations());

        $this->user()->approve($request);

        $this->assertInstanceOf(DeferredCreationModel::class, $request->fresh()->modifiable);
    }

    public function test_an_approved_creation_behaves_like_any_model(): void
    {
        $request = DeferredCreationModel::create(['name' => 'ACME', 'status' => 'gated'])->getLastModification();
        $this->user()->approve($request);

        $model = DeferredCreationModel::first();
        $model->name = 'Renamed';
        $model->save();

        $this->assertSame('Renamed', DeferredCreationModel::first()->name);
        $this->assertSame(['ori' => 'ACME', 'mod' => 'Renamed'], $model->getLastModification()->modifications['name']);
    }

    // Draft mode

    public function test_draft_inserts_a_hidden_row(): void
    {
        $model = DraftCreationModel::create(['name' => 'ACME', 'status' => 'gated']);

        $this->assertTrue($model->exists);
        $this->assertNotNull($model->id);
        $this->assertSame(1, $this->rows());

        $this->assertNull(DraftCreationModel::find($model->id));
        $this->assertSame(0, DraftCreationModel::count());
        $this->assertNotNull(DraftCreationModel::withApprovalDrafts()->find($model->id));
        $this->assertCount(1, DraftCreationModel::onlyApprovalDrafts()->get());

        $request = $model->getLastModification()->fresh();
        $this->assertSame('pending', $request->status);
        $this->assertFalse($request->is_update);
        $this->assertSame($model->id, (int) $request->modifiable_id);
        $this->assertArrayHasKey('name', $request->modifications->toArray());
        $this->assertArrayNotHasKey('approval_pending', $request->modifications->toArray());
    }

    public function test_draft_is_revealed_by_approval(): void
    {
        $model = DraftCreationModel::create(['name' => 'ACME', 'status' => 'gated']);
        $request = $model->getLastModification();

        $this->assertTrue($this->user()->approve($request));

        $this->assertSame('ACME', DraftCreationModel::find($model->id)->name);
        $this->assertCount(0, DraftCreationModel::onlyApprovalDrafts()->get());
        $this->assertSame('approved', $request->fresh()->status);
        $this->assertTrue($request->fresh()->modifiable->is($model));
        $this->assertSame(1, Modification::count(), 'revealing the row is not an audited change');
    }

    public function test_draft_waits_for_every_approver(): void
    {
        $model = TwoApproverDraftModel::create(['name' => 'ACME', 'status' => 'gated']);
        $request = $model->getLastModification();

        $this->user('a')->approve($request);
        $this->assertNull(TwoApproverDraftModel::find($model->id));

        $this->user('b')->approve($request);
        $this->assertNotNull(TwoApproverDraftModel::find($model->id));
    }

    public function test_draft_is_deleted_by_rejection(): void
    {
        $model = DraftCreationModel::create(['name' => 'ACME', 'status' => 'gated']);
        $request = $model->getLastModification();

        $this->assertTrue($this->user()->disapprove($request));

        $this->assertSame(0, $this->rows());
        $this->assertSame('rejected', $request->fresh()->status);
    }

    public function test_draft_is_soft_deleted_by_rejection_when_the_model_uses_it(): void
    {
        $model = SoftDeletingDraftModel::create(['name' => 'ACME', 'status' => 'gated']);

        $this->user()->disapprove($model->getLastModification());

        $this->assertSame(1, $this->rows());
        $this->assertNotNull(Capsule::table('regression_models')->value('deleted_at'));
    }

    public function test_a_rejected_draft_frees_its_unique_values(): void
    {
        $request = DraftCreationModel::create(['name' => 'a', 'status' => 'gated', 'code' => 'dup'])->getLastModification();
        $this->user()->disapprove($request);

        $this->assertTrue(DraftCreationModel::create(['name' => 'b', 'code' => 'dup'])->exists);
    }

    public function test_draft_edits_are_kept_when_approved(): void
    {
        $model = DraftCreationModel::create(['name' => 'ACME', 'status' => 'gated']);
        $request = $model->getLastModification();

        $draft = DraftCreationModel::withApprovalDrafts()->find($model->id);
        $draft->name = 'Edited';
        $draft->save();

        $this->user()->approve($request);

        $this->assertSame('Edited', DraftCreationModel::find($model->id)->name);
    }

    public function test_draft_deleted_meanwhile_cant_be_approved_but_can_be_rejected(): void
    {
        $model = DraftCreationModel::create(['name' => 'ACME', 'status' => 'gated']);
        $request = $model->getLastModification();
        Capsule::table('regression_models')->delete();

        $this->assertFalse($this->user('a')->approve($request));
        $this->assertSame('pending', $request->fresh()->status);

        $this->assertTrue($this->user('b')->disapprove($request));
        $this->assertSame('rejected', $request->fresh()->status);
    }

    public function test_draft_works_with_a_vetoed_insert(): void
    {
        ConfiguredCreationModel::$veto = true;

        $model = DraftCreationModel::create(['name' => 'ACME', 'status' => 'gated']);

        $this->assertFalse($model->exists);
        $this->assertSame(0, Modification::count());
    }

    public function test_draft_forcing_an_approval_reveals_the_row(): void
    {
        $model = DraftCreationModel::create(['name' => 'ACME', 'status' => 'gated']);

        $model->getLastModification()->fresh()->forceApprovalUpdate();

        $this->assertNotNull(DraftCreationModel::find($model->id));
    }

    // Both modes

    /**
     * @return array<string, array{class-string<ConfiguredCreationModel>}>
     */
    public static function modes(): array
    {
        return ['deferred' => [DeferredCreationModel::class], 'draft' => [DraftCreationModel::class]];
    }

    /**
     * @return array<string, array{class-string<ConfiguredCreationModel>, bool}>
     */
    public static function modesAndDecisions(): array
    {
        return [
            'deferred, voted' => [DeferredCreationModel::class, false],
            'deferred, forced' => [DeferredCreationModel::class, true],
            'draft, voted' => [DraftCreationModel::class, false],
            'draft, forced' => [DraftCreationModel::class, true],
        ];
    }

    /**
     * @dataProvider modesAndDecisions
     */
    public function test_an_approved_creation_dates_from_its_request(string $class, bool $forced): void
    {
        Carbon::setTestNow('2026-01-01 10:00:00');
        $request = $class::create(['name' => 'ACME', 'status' => 'gated'])->getLastModification();

        Carbon::setTestNow('2026-01-06 15:30:00');
        $forced ? $request->fresh()->forceApprovalUpdate() : $this->user()->approve($request);

        $row = $class::where('name', 'ACME')->first();

        $this->assertSame('2026-01-01 10:00:00', $row->created_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-01-06 15:30:00', $row->updated_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-01-01 10:00:00', $request->fresh()->occurred_at->format('Y-m-d H:i:s'));
    }

    public function test_a_deferred_creation_keeps_a_created_at_set_by_hand(): void
    {
        Carbon::setTestNow('2026-01-01 10:00:00');
        $model = new DeferredCreationModel(['name' => 'ACME', 'status' => 'gated']);
        $model->created_at = '2025-06-15 08:00:00';
        $model->save();

        Carbon::setTestNow('2026-01-06 15:30:00');
        $this->user()->approve($model->getLastModification());

        $row = DeferredCreationModel::where('name', 'ACME')->first();

        $this->assertSame('2025-06-15 08:00:00', $row->created_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-01-06 15:30:00', $row->updated_at->format('Y-m-d H:i:s'));
    }

    public function test_a_deferred_creation_without_timestamps_stays_without_them(): void
    {
        $request = DeferredNoTimestampsModel::create(['name' => 'ACME', 'status' => 'gated'])->getLastModification();

        $this->user()->approve($request);

        $this->assertNull(Capsule::table('regression_models')->where('name', 'ACME')->value('created_at'));
    }

    public function test_a_deferred_creation_without_a_created_at_column_still_inserts(): void
    {
        $request = DeferredNoCreatedAtModel::create(['name' => 'ACME', 'status' => 'gated'])->getLastModification();

        $this->user()->approve($request);

        $row = Capsule::table('regression_models')->where('name', 'ACME')->first();

        $this->assertNull($row->created_at);
        $this->assertNotNull($row->updated_at);
    }

    /**
     * @dataProvider modes
     */
    public function test_a_creation_can_be_resolved_with_no_vote(string $class): void
    {
        $class::create(['name' => 'ACME', 'status' => 'gated']);

        foreach ($class::pendingCreations() as $request) {
            $request->forceApprovalCreate();
        }

        $request = Modification::sole();

        $this->assertSame('approved', $request->status);
        $this->assertSame(0, $request->approvals()->count());
        $this->assertSame('ACME', $request->modifications['name']['mod']);
        $this->assertSame('ACME', $request->modifiable->name);
        $this->assertCount(0, $class::pendingCreations());
        $this->assertSame(1, $class::count());
    }

    /**
     * @dataProvider modes
     */
    public function test_force_create_works_on_the_instance_the_save_returned(string $class): void
    {
        $class::create(['name' => 'ACME', 'status' => 'gated'])->getLastModification()->forceApprovalCreate();

        $this->assertSame(1, $class::count());
        $this->assertSame('approved', Modification::sole()->status);
    }

    /**
     * @dataProvider modes
     */
    public function test_force_create_refuses_a_change_to_an_existing_row(string $class): void
    {
        $model = $class::create(['name' => 'ACME', 'status' => 'open']);
        $model->requiresApproval(fn () => true);
        $model->update(['name' => 'changed']);
        $request = $model->getLastModification();

        try {
            $request->fresh()->forceApprovalCreate();
            $this->fail('only a creation can be forced under this name');
        } catch (\LogicException) {
        }

        $this->assertSame('pending', $request->fresh()->status);
        $this->assertSame('ACME', $class::find($model->id)->name);
    }

    /**
     * @dataProvider modes
     */
    public function test_force_create_leaves_a_decided_creation_alone(string $class): void
    {
        $request = $class::create(['name' => 'ACME', 'status' => 'gated'])->getLastModification();
        $this->user()->disapprove($request);

        $request->fresh()->forceApprovalCreate();

        $this->assertSame('rejected', $request->fresh()->status);
        $this->assertSame(0, $class::count());
    }

    /**
     * @dataProvider modes
     */
    public function test_resolving_a_creation_with_no_vote_stays_pending_if_a_saving_listener_vetoes(string $class): void
    {
        $request = $class::create(['name' => 'ACME', 'status' => 'gated'])->getLastModification();

        // A draft's row is already in, so this is what can stop the save revealing it.
        $class::saving(fn () => false);
        $request->fresh()->forceApprovalUpdate();

        $this->assertSame('pending', $request->fresh()->status);
        $this->assertSame(0, $class::count());
    }

    /**
     * @dataProvider modes
     */
    public function test_creations_the_gate_doesnt_catch_save_normally(string $class): void
    {
        $model = $class::create(['name' => 'ACME', 'status' => 'open']);

        $this->assertNotNull($class::find($model->id));
        $this->assertSame(0, Modification::count());
    }

    /**
     * @dataProvider modes
     */
    public function test_the_modifier_is_recorded_on_the_request(string $class): void
    {
        $user = $this->user();
        test_set_authenticated_user($user);

        $request = $class::create(['name' => 'ACME', 'status' => 'gated'])->getLastModification()->fresh();

        $this->assertTrue($request->modifier->is($user));
    }

    public function test_the_mode_comes_from_the_config(): void
    {
        $this->assertFalse(ConfiguredCreationModel::create(['name' => 'a', 'status' => 'gated'])->exists);

        test_set_config('approvals.creation.mode', 'draft');

        $this->assertTrue(ConfiguredCreationModel::create(['name' => 'b', 'status' => 'gated'])->exists);
    }

    public function test_a_model_overrides_the_config(): void
    {
        test_set_config('approvals.creation.mode', 'draft');

        $this->assertFalse(DeferredCreationModel::create(['name' => 'a', 'status' => 'gated'])->exists);
    }

    public function test_an_unknown_mode_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        UnknownModeModel::create(['name' => 'a', 'status' => 'gated']);
    }

    public function test_pending_rows_survive_a_mode_change(): void
    {
        $deferred = ConfiguredCreationModel::create(['name' => 'deferred', 'status' => 'gated'])->getLastModification();

        test_set_config('approvals.creation.mode', 'draft');
        $draft = ConfiguredCreationModel::create(['name' => 'draft', 'status' => 'gated']);
        $this->assertNull(ConfiguredCreationModel::find($draft->id));

        $this->user('a')->approve($deferred);
        $this->assertSame('deferred', ConfiguredCreationModel::first()->name);

        test_set_config('approvals.creation.mode', 'deferred');
        $this->assertTrue($this->user('b')->approve($draft->getLastModification()));
        $this->assertSame('approved', $draft->getLastModification()->fresh()->status);
    }
}
