<?php

declare(strict_types=1);

namespace ReynoTECH\Approvals\Tests\Feature;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use ReynoTECH\Approvals\Concerns\RequiresApprovalWhenChanges;
use ReynoTECH\Approvals\Contracts\GatesCreation;
use ReynoTECH\Approvals\Models\Modification;
use ReynoTECH\Approvals\Tests\Fixtures\ApproverModel;
use ReynoTECH\Approvals\Tests\TestCase;

class ContractModel extends Model
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

class TwoDisapproverModel extends ContractModel
{
    protected function disapproversRequired(): int
    {
        return 2;
    }
}

class GroupedContractModel extends ContractModel
{
    protected function approvableItemGroups(array $dirtyKeys): array
    {
        return ['status' => ['status'], 'notes' => ['notes']];
    }
}

class NoUpdateGroupedModel extends GroupedContractModel
{
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->updateWhenApproved = false;
    }
}

class DeleteOnRejectModel extends ContractModel
{
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->deleteWhenDisapproved = true;
    }
}

class DeleteOnDecisionDeferredModel extends ContractModel implements GatesCreation
{
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->deleteWhenApproved = true;
        $this->deleteWhenDisapproved = true;
    }

    public function requiresApprovalOnCreate(array $attributes): bool
    {
        return true;
    }
}

class UntrackedDeferredModel extends ContractModel implements GatesCreation
{
    public array $noTrackModification = ['notes'];

    public function requiresApprovalOnCreate(array $attributes): bool
    {
        return true;
    }
}

class DateFormatsModel extends ContractModel
{
    protected $casts = ['stamp' => 'date:d/m/Y', 'secret' => 'immutable_date:d/m/Y', 'payload' => 'immutable_date'];
}

class CachedAccessorModel extends ContractModel
{
    protected function payload(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => (object) ['raw' => $value],
            set: fn ($value) => $value instanceof \stdClass ? $value->raw : $value,
        );
    }
}

class SiblingSetterModel extends ContractModel
{
    protected function payload(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => json_decode((string) $value, true),
            set: fn ($value, $attributes) => json_encode(['code' => $attributes['code'], 'list' => $value['list'] ?? $value]),
        );
    }
}

final class ModelApiContractTest extends TestCase
{
    private function user(string $name = 'Inspector'): ApproverModel
    {
        return ApproverModel::create(['name' => $name]);
    }

    private function stored(ContractModel $model): ContractModel
    {
        return $model::find($model->id);
    }

    private function held(ContractModel $model, array $changes): Modification
    {
        $model->gate = true;
        $model->forceFill($changes)->save();
        $model->gate = false;

        return $model->getLastModification();
    }

    public function test_a_callback_gate_holds_the_change_and_receives_the_dirty_attributes(): void
    {
        $model = $this->stored(ContractModel::create(['name' => 'n', 'status' => 'draft']));
        $seen = null;

        $model->requiresApproval(function (array $dirty) use (&$seen) {
            $seen = $dirty;

            return true;
        });
        $model->status = 'one';

        $this->assertFalse($model->save());

        $this->assertSame(['status' => 'one'], $seen);
        $this->assertSame('draft', $this->stored($model)->status);
        $this->assertSame('pending', $model->getLastModification()->status);
    }

    public function test_a_callback_gate_returning_false_saves_directly(): void
    {
        $model = $this->stored(ContractModel::create(['name' => 'n', 'status' => 'draft']));

        $model->requiresApproval(fn () => false);
        $model->status = 'one';

        $this->assertTrue($model->save());

        $this->assertSame('one', $this->stored($model)->status);
        $this->assertSame('applied', $model->getLastModification()->status);
    }

    public function test_repeating_a_held_change_doesnt_write_it(): void
    {
        $model = $this->stored(ContractModel::create(['name' => 'n', 'status' => 'draft']));

        $this->held($model, ['status' => 'one']);

        $model->gate = true;
        $model->status = 'one';
        $this->assertFalse($model->save());

        $this->assertSame('draft', $this->stored($model)->status);
        $this->assertSame('draft', $model->status);
        $this->assertFalse($model->isDirty());
    }

    public function test_repeating_a_deferred_creation_doesnt_insert_it(): void
    {
        $first = UntrackedDeferredModel::create(['name' => 'n']);
        $second = UntrackedDeferredModel::create(['name' => 'n']);

        $this->assertFalse($second->exists);
        $this->assertTrue($first->getLastModification()->is($second->getLastModification()));
        $this->assertSame(0, Capsule::table('regression_models')->count());
    }

    public function test_a_deferred_creation_keeps_its_untracked_columns(): void
    {
        $request = UntrackedDeferredModel::create(['name' => 'n', 'notes' => 'kept'])->getLastModification();

        $this->user()->approve($request);

        $this->assertSame('kept', Capsule::table('regression_models')->value('notes'));
    }

    public function test_a_full_save_of_changes_only_records_every_column_when_something_changed(): void
    {
        $model = $this->stored(ContractModel::create(['name' => 'n', 'status' => 'draft']));

        $model->withFullSaveOnlyUpdateModification();
        $model->status = 'one';
        $model->save();

        $diff = $model->getLastModification()->modifications->toArray();
        $this->assertSame(['ori' => 'draft', 'mod' => 'one'], $diff['status']);
        $this->assertSame(['ori' => 'n', 'mod' => 'n'], $diff['name']);
    }

    public function test_a_full_save_of_changes_only_with_nothing_changed_leaves_no_audit_row(): void
    {
        $model = $this->stored(ContractModel::create(['name' => 'n']));

        $model->withFullSaveOnlyUpdateModification();
        $model->save();

        $this->assertSame(0, Modification::count());
    }

    public function test_a_repeated_deferred_creation_doesnt_pass_its_meta_on(): void
    {
        $first = new UntrackedDeferredModel(['name' => 'n']);
        $first->withModificationMeta(['request' => 1]);
        $first->save();

        $repeat = new UntrackedDeferredModel(['name' => 'n']);
        $repeat->withModificationMeta(['request' => 'repeat']);
        $repeat->save();
        $repeat->name = 'other';
        $repeat->save();

        $this->assertNull($repeat->getLastModification()->fresh()->meta);
    }

    public function test_recording_on_a_new_model_is_a_creation(): void
    {
        $record = (new ContractModel(['name' => 'n']))->recordModification('imported');

        $this->assertFalse($record->fresh()->is_update);
    }

    public function test_applying_a_modification_with_items_directly_resolves_them(): void
    {
        $model = GroupedContractModel::create(['name' => 'n', 'status' => 'draft', 'notes' => 'a']);
        $modification = $this->held($model, ['status' => 'one', 'notes' => 'b'])->fresh();

        GroupedContractModel::find($model->id)->applyModificationChanges($modification, true);

        $this->assertSame(['applied', 'applied'], $modification->items()->pluck('status')->all());
    }

    public function test_applying_without_updating_directly_marks_items_approved(): void
    {
        $model = NoUpdateGroupedModel::create(['name' => 'n', 'status' => 'draft']);
        $modification = $this->held($model, ['status' => 'one'])->fresh();

        NoUpdateGroupedModel::find($model->id)->applyModificationChanges($modification, true);

        $this->assertSame(['approved'], $modification->items()->pluck('status')->all());
    }

    public function test_forcing_a_single_save_past_the_gate(): void
    {
        $model = $this->stored(ContractModel::create(['name' => 'n', 'status' => 'draft']));
        $model->gate = true;
        $model->setForcedApprovalUpdate();

        $model->status = 'one';
        $this->assertTrue($model->save());

        $this->assertSame('one', $this->stored($model)->status);
    }

    public function test_force_modification_records_a_row_with_an_empty_diff(): void
    {
        $model = $this->stored(ContractModel::create(['name' => 'n']));

        $model->forceModification();
        $model->save();

        $modification = $model->getLastModification();
        $this->assertSame('applied', $modification->status);
        $this->assertSame([], $modification->fresh()->modifications->toArray());
    }

    public function test_no_save_modifications_skips_the_audit_row(): void
    {
        $model = $this->stored(ContractModel::create(['name' => 'n']));

        $model->noSaveModifications();
        $model->name = 'x';
        $model->save();

        $this->assertSame('x', $this->stored($model)->name);
        $this->assertSame(0, Modification::count());
    }

    public function test_touch_leaves_no_audit_row(): void
    {
        $model = ContractModel::create(['name' => 'n']);
        Capsule::table('regression_models')->update(['updated_at' => '2020-01-01 00:00:00']);
        $model = $this->stored($model);

        $this->assertTrue($model->touch());

        $this->assertNotSame('2020-01-01 00:00:00', Capsule::table('regression_models')->value('updated_at'));
        $this->assertSame(0, Modification::count());
    }

    public function test_a_gated_touch_saves_and_holds_nothing(): void
    {
        $model = ContractModel::create(['name' => 'n']);
        Capsule::table('regression_models')->update(['updated_at' => '2020-01-01 00:00:00']);
        $model = $this->stored($model);
        $model->gate = true;

        $this->assertTrue($model->touch());

        $this->assertNotSame('2020-01-01 00:00:00', Capsule::table('regression_models')->value('updated_at'));
        $this->assertSame(0, Modification::count());
    }

    public function test_the_required_disapprovers_are_stored_and_respected(): void
    {
        $model = TwoDisapproverModel::create(['name' => 'n', 'status' => 'draft']);
        $modification = $this->held($model, ['status' => 'one']);

        $this->assertSame(2, $modification->fresh()->disapprovers_required);

        $this->user('a')->disapprove($modification);
        $this->assertSame('pending', $modification->fresh()->status);
        $this->assertSame(1, $modification->fresh()->disapproversRemaining);

        $this->user('b')->disapprove($modification);
        $this->assertSame('rejected', $modification->fresh()->status);
    }

    public function test_occurred_at_is_recorded(): void
    {
        $model = $this->stored(ContractModel::create(['name' => 'n']));
        $model->name = 'x';
        $model->save();
        $this->assertNotNull($model->getLastModification()->fresh()->occurred_at);

        $at = new \DateTimeImmutable('2020-05-05 10:00:00');
        $record = $model->recordModification('event', occurredAt: $at);
        $this->assertSame('2020-05-05 10:00:00', $record->fresh()->occurred_at->format('Y-m-d H:i:s'));
    }

    public function test_record_modification_stores_nulls_and_becomes_the_last_one(): void
    {
        $model = ContractModel::create(['name' => 'n']);

        $record = $model->recordModification('event', [['key' => 'a'], ['key' => 'b', 'original' => 1, 'proposed' => 2]]);

        $this->assertTrue($model->getLastModification()->is($record));
        $this->assertDiffSame(
            ['a' => ['ori' => null, 'mod' => null], 'b' => ['ori' => 1, 'mod' => 2]],
            $record->fresh()->modifications->toArray()
        );
    }

    public function test_query_scopes_separate_rows(): void
    {
        $model = $this->stored(ContractModel::create(['name' => 'n', 'status' => 'draft']));
        $model->status = 'audit';
        $model->save();
        $audit = $model->getLastModification();
        $pending = $this->held($this->stored($model), ['status' => 'held']);
        $created = $model->recordModification('created');
        $created->update(['is_update' => false]);

        $this->assertSame([$pending->id], Modification::activeOnly()->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$audit->id, $created->id], Modification::inactiveOnly()->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$audit->id, $pending->id], Modification::changes()->pluck('id')->all());
        $this->assertSame([$created->id], Modification::creations()->pluck('id')->all());
    }

    public function test_legacy_flags_follow_flow_and_status(): void
    {
        $model = ContractModel::create(['name' => 'n', 'status' => 'draft']);
        $modification = $this->held($model, ['status' => 'one'])->fresh();

        $this->assertTrue($modification->active);
        $this->assertTrue($modification->is_modification);

        $this->user()->approve($modification);
        $fresh = $modification->fresh();
        $this->assertFalse($fresh->active);
        $this->assertTrue($fresh->is_modification);
    }

    public function test_a_row_without_flow_keeps_the_flags_it_was_given(): void
    {
        $model = ContractModel::create(['name' => 'n']);
        $modification = new Modification([
            'md5' => 'x', 'modifications' => [], 'modifiable_id' => $model->id, 'modifiable_type' => ContractModel::class,
            'flow' => 'approval', 'status' => null, 'active' => false, 'is_modification' => false,
        ]);
        $modification->save();

        $fresh = $modification->fresh();
        $this->assertFalse($fresh->active);
        $this->assertFalse($fresh->is_modification);
    }

    public function test_update_when_approved_false_resolves_items_without_writing(): void
    {
        $model = NoUpdateGroupedModel::create(['name' => 'n', 'status' => 'draft', 'notes' => 'a']);
        $modification = $this->held($model, ['status' => 'one', 'notes' => 'b']);

        $this->assertTrue($this->user()->approve($modification));

        $this->assertSame(['approved', 'approved'], $modification->items()->pluck('status')->all());
        $this->assertSame('approved', $modification->fresh()->status);
        $fresh = $this->stored($model);
        $this->assertSame('draft', $fresh->status);
        $this->assertSame('a', $fresh->notes);
    }

    public function test_forcing_with_update_when_approved_false_marks_items_approved(): void
    {
        $model = NoUpdateGroupedModel::create(['name' => 'n', 'status' => 'draft']);
        $modification = $this->held($model, ['status' => 'one'])->fresh();

        $modification->forceApprovalUpdate();

        $this->assertSame(['approved'], $modification->items()->pluck('status')->all());
    }

    public function test_forcing_applies_and_marks_items_applied(): void
    {
        $model = GroupedContractModel::create(['name' => 'n', 'status' => 'draft']);
        $modification = $this->held($model, ['status' => 'one'])->fresh();

        $modification->forceApprovalUpdate();

        $this->assertSame(['applied'], $modification->items()->pluck('status')->all());
    }

    public function test_rejecting_directly_resolves_pending_items(): void
    {
        $model = GroupedContractModel::create(['name' => 'n', 'status' => 'draft']);
        $modification = $this->held($model, ['status' => 'one'])->fresh();

        $model->applyModificationChanges($modification, false);

        $this->assertSame(['rejected'], $modification->items()->pluck('status')->all());
        $this->assertSame('rejected', $modification->fresh()->status);
    }

    public function test_delete_when_disapproved_removes_the_row(): void
    {
        $model = DeleteOnRejectModel::create(['name' => 'n', 'status' => 'draft']);
        $modification = $this->held($model, ['status' => 'one']);

        $this->user()->disapprove($modification);

        $this->assertNull(Modification::find($modification->id));
        $this->assertSame('draft', $this->stored($model)->status);
    }

    public function test_delete_flags_apply_to_creations(): void
    {
        $approved = DeleteOnDecisionDeferredModel::create(['name' => 'a'])->getLastModification();
        $rejected = DeleteOnDecisionDeferredModel::create(['name' => 'b'])->getLastModification();

        $this->user('a')->approve($approved);
        $this->user('b')->disapprove($rejected);

        $this->assertSame(0, Modification::count());
        $this->assertSame(['a'], Capsule::table('regression_models')->pluck('name')->all());
    }

    public function test_applying_to_an_instance_replaces_its_cached_object(): void
    {
        $model = CachedAccessorModel::create(['name' => 'n', 'payload' => 'old']);
        $modification = $this->held($this->stored($model), ['payload' => 'new'])->fresh();

        $instance = CachedAccessorModel::find($model->id);
        $this->assertSame('old', $instance->payload->raw, 'caches the object');

        $instance->applyModificationChanges($modification, true);

        $this->assertSame('new', Capsule::table('regression_models')->value('payload'));
        $this->assertSame('new', $instance->payload->raw);
    }

    public function test_a_setter_reading_another_attribute_sees_it_while_diffing(): void
    {
        $model = SiblingSetterModel::create(['name' => 'n', 'code' => 'c1', 'payload' => ['a']]);
        $model = SiblingSetterModel::find($model->id);

        $model->payload = ['b'];
        $model->save();

        $this->assertSame(
            '{"code":"c1","list":["b"]}',
            Capsule::table('regression_models')->value('payload')
        );
        $this->assertArrayHasKey('payload', $model->getLastModification()->modifications->toArray());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function dateOnlyCasts(): array
    {
        return [
            'date with a format' => ['stamp', '2026-02-02'],
            'immutable date with a format' => ['secret', '2026-02-02'],
            'immutable date' => ['payload', '2026-02-02'],
        ];
    }

    /**
     * @dataProvider dateOnlyCasts
     */
    public function test_date_only_casts_keep_only_the_date(string $key, string $value): void
    {
        $model = DateFormatsModel::find(DateFormatsModel::create(['name' => 'n'])->id);

        $model->{$key} = $value;
        $model->save();

        $this->assertSame($value, $model->getLastModification()->modifications[$key]['mod']);
    }

    public function test_a_stale_item_whose_modification_is_gone_takes_no_vote(): void
    {
        $model = GroupedContractModel::create(['name' => 'n', 'status' => 'draft']);
        $modification = $this->held($model, ['status' => 'one']);
        $item = $modification->items()->first();
        $orphan = clone $item;
        $orphan->unsetRelation('modification');
        Capsule::table('modifications')->where('id', $modification->id)->delete();

        $this->assertFalse($this->user('a')->approveItem($orphan));
        $this->assertFalse($this->user('b')->disapproveItem($orphan));
    }

    public function test_a_stale_item_whose_row_is_gone_takes_no_vote(): void
    {
        $model = GroupedContractModel::create(['name' => 'n', 'status' => 'draft']);
        $modification = $this->held($model, ['status' => 'one']);
        $item = $modification->items()->first();
        Capsule::table('modification_items')->where('id', $item->id)->delete();

        $this->assertFalse($this->user()->approveItem($item));
    }
}
