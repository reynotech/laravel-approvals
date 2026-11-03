<?php

declare(strict_types=1);

namespace ReynoTECH\Approvals\Tests\Feature;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Fluent;
use ReynoTECH\Approvals\Concerns\RequiresApprovalWhenChanges;
use ReynoTECH\Approvals\Contracts\GatesCreation;
use ReynoTECH\Approvals\Models\Approval;
use ReynoTECH\Approvals\Models\Modification;
use ReynoTECH\Approvals\Models\ModificationItem;
use ReynoTECH\Approvals\Tests\Fixtures\ApproverModel;
use ReynoTECH\Approvals\Tests\TestCase;
use Spatie\SchemalessAttributes\Casts\SchemalessAttributes;

class EdgeModel extends Model
{
    use RequiresApprovalWhenChanges;

    public const CASTS = [];

    public const GROUPS = [];

    public static bool $veto = false;

    public static bool $vetoDelete = false;

    public static bool $saveAgainWhenCreated = false;

    protected $table = 'regression_models';

    protected $guarded = ['id'];

    public bool $gate = false;

    protected static function booted(): void
    {
        static::updating(fn (Model $model) => self::$veto && $model->getAttribute('notes') === 'veto me' ? false : null);
        static::deleting(fn () => self::$vetoDelete ? false : null);
        static::created(function (Model $model) {
            if (self::$saveAgainWhenCreated) {
                $model->withoutEvents(fn () => null);
                $model->save();
            }
        });
    }

    protected function casts(): array
    {
        return static::CASTS;
    }

    protected function requiresApprovalWhenTrait(array $modifications): bool
    {
        return $this->gate;
    }

    protected function approvableItemGroups(array $dirtyKeys): array
    {
        return static::GROUPS;
    }
}

class StatusNotesGroupedModel extends EdgeModel
{
    public const GROUPS = ['status' => ['status'], 'notes' => ['notes']];
}

class NotesGroupedModel extends EdgeModel
{
    public const GROUPS = ['notes' => ['notes']];
}

class DraftEdgeModel extends EdgeModel implements GatesCreation
{
    public function requiresApprovalOnCreate(array $attributes): bool
    {
        return ($attributes['status'] ?? null) === 'gated';
    }

    public function approvalCreationMode(): string
    {
        return GatesCreation::DRAFT;
    }
}

class DeferredEdgeModel extends DraftEdgeModel
{
    public function approvalCreationMode(): string
    {
        return GatesCreation::DEFERRED;
    }
}

class DeferredWithHelperModel extends DeferredEdgeModel
{
    public function status(): string
    {
        return 'not a relation';
    }
}

class SoftEdgeModel extends EdgeModel
{
    use SoftDeletes;
}

class AuditedModification extends Modification
{
}

class AuditedItem extends ModificationItem
{
}

class SoftModification extends Modification
{
    use SoftDeletes;
}

class DeleteApprovedModel extends EdgeModel
{
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->deleteWhenApproved = true;
    }
}

class DecimalModel extends EdgeModel
{
    public const CASTS = ['payload' => 'decimal:2'];
}

class DecimalGroupedModel extends DecimalModel
{
    public const GROUPS = ['payload' => ['payload']];
}

class IntegerCastModel extends EdgeModel
{
    public const CASTS = ['payload' => 'integer'];
}

class DateCastModel extends EdgeModel
{
    public const CASTS = ['stamp' => 'date'];
}

class CustomDateFormatModel extends EdgeModel
{
    public const CASTS = ['stamp' => 'date:Y-m-d'];
}

class StampModel extends EdgeModel
{
    public const CASTS = ['stamp' => 'timestamp'];
}

class ArrayCastModel extends EdgeModel
{
    public const CASTS = ['payload' => 'array'];
}

class SchemalessModel extends EdgeModel
{
    public const CASTS = ['payload' => SchemalessAttributes::class];
}

class AuditingArrayMutatorModel extends EdgeModel
{
    public const CASTS = ['payload' => 'array'];

    public function setPayloadAttribute($value): void
    {
        $this->attributes['payload'] = json_encode([...$value, 'audit']);
    }
}

class GetterOnlyArrayModel extends EdgeModel
{
    protected function notes(): Attribute
    {
        return Attribute::make(get: fn ($value) => $value === null ? null : explode(',', $value));
    }
}

class GetterArraySetterStringModel extends EdgeModel
{
    protected function notes(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value === null ? null : explode(',', $value),
            set: fn (string $value) => strtolower($value),
        );
    }
}

final class Address extends Fluent
{
}

class AddressCast implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes)
    {
        return $value === null ? null : new Address(json_decode($value, true));
    }

    public function set($model, string $key, $value, array $attributes)
    {
        if ($value !== null && ! $value instanceof Address) {
            throw new \InvalidArgumentException('expected an Address');
        }

        return $value === null ? null : json_encode($value->toArray());
    }
}

class AddressModel extends EdgeModel
{
    public const CASTS = ['payload' => AddressCast::class];
}

/** Has `set()` and `toArray()`, like a schemaless attribute, but can't be queried like one. */
final class Settings implements \ArrayAccess
{
    public function __construct(public array $values)
    {
    }

    public function set(string $key, $value): void
    {
        $this->values[$key] = $value;
    }

    public function toArray(): array
    {
        return $this->values;
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->values[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->values[$offset];
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->values[$offset] = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->values[$offset]);
    }
}

class SettingsCast implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes)
    {
        return $value === null ? null : new Settings(json_decode($value, true));
    }

    public function set($model, string $key, $value, array $attributes)
    {
        return $value === null ? null : json_encode($value instanceof Settings ? $value->toArray() : $value);
    }
}

class SettingsModel extends EdgeModel
{
    public const CASTS = ['payload' => SettingsCast::class];
}

class CollidingColumnsModel extends Model
{
    use RequiresApprovalWhenChanges;

    public $timestamps = false;

    protected $table = 'colliding_columns';

    protected $guarded = [];

    public bool $gate = false;

    protected function requiresApprovalWhenTrait(array $modifications): bool
    {
        return $this->gate;
    }
}

class ListenerOrderModel extends Model
{
    use RequiresApprovalWhenChanges;

    public static array $calls = [];

    public static bool $veto = false;

    public static bool $gated = false;

    protected $table = 'regression_models';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::saving(function () {
            self::$calls[] = 'saving';

            return self::$veto ? false : null;
        });
    }

    protected function requiresApprovalWhen(array $modifications): bool
    {
        return self::$gated;
    }
}

final class ApprovalEdgeCasesTest extends TestCase
{
    private function resetListenerModel(): void
    {
        ListenerOrderModel::$calls = [];
        ListenerOrderModel::$veto = false;
        ListenerOrderModel::$gated = false;
    }

    public function test_saving_listeners_in_booted_run_on_saves_the_gate_lets_through(): void
    {
        $this->resetListenerModel();

        $model = ListenerOrderModel::create(['name' => 'one']);
        $this->assertSame(['saving'], ListenerOrderModel::$calls);

        $model->update(['name' => 'two']);
        $this->assertSame(['saving', 'saving'], ListenerOrderModel::$calls);
    }

    public function test_a_saving_listener_in_booted_can_veto_and_leaves_no_audit_row(): void
    {
        $this->resetListenerModel();
        $model = ListenerOrderModel::create(['name' => 'one']);
        $audits = Modification::count();

        ListenerOrderModel::$veto = true;

        $this->assertFalse($model->update(['name' => 'two']));
        $this->assertSame('one', ListenerOrderModel::find($model->id)->name);
        $this->assertSame($audits, Modification::count());
    }

    public function test_a_save_the_gate_holds_doesnt_reach_later_listeners(): void
    {
        $this->resetListenerModel();
        $model = ListenerOrderModel::create(['name' => 'one']);

        ListenerOrderModel::$calls = [];
        ListenerOrderModel::$gated = true;
        $model->update(['name' => 'two']);

        $this->assertSame([], ListenerOrderModel::$calls);
        $this->assertSame('pending', $model->getLastModification()->status);
    }

    public function test_a_saving_listener_in_booted_can_veto_a_forced_change(): void
    {
        $this->resetListenerModel();
        $model = ListenerOrderModel::create(['name' => 'one']);

        ListenerOrderModel::$gated = true;
        $model->update(['name' => 'two']);
        $request = $model->getLastModification();

        ListenerOrderModel::$veto = true;
        $request->fresh()->forceApprovalUpdate();

        $this->assertSame('pending', $request->fresh()->status);
        $this->assertSame('one', ListenerOrderModel::find($model->id)->name);
    }

    protected function setUp(): void
    {
        parent::setUp();

        set_error_handler(function ($level, $message, $file, $line) {
            throw new \ErrorException($message, 0, $level, $file, $line);
        }, E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        EdgeModel::$veto = false;
        EdgeModel::$vetoDelete = false;
        EdgeModel::$saveAgainWhenCreated = false;
        test_set_config('approvals.models.modification', Modification::class);
        test_set_config('approvals.models.modification_item', ModificationItem::class);

        parent::tearDown();
    }

    private function user(string $name = 'Inspector'): ApproverModel
    {
        return ApproverModel::create(['name' => $name]);
    }

    private function raw(Model $model, string $column)
    {
        return Capsule::table('regression_models')->where('id', $model->getKey())->value($column);
    }

    private function held(Model $model, array $changes): Modification
    {
        $model->gate = true;

        foreach ($changes as $key => $value) {
            $model->setAttribute($key, $value);
        }

        $model->save();
        $model->gate = false;

        return $model->getLastModification();
    }

    /** Applying the same change directly and through approval must store exactly the same. */
    private function assertApprovalMatchesDirect(string $class, array $start, array $changes): void
    {
        $direct = $class::find($class::create($start)->id);
        foreach ($changes as $key => $value) {
            $direct->setAttribute($key, $value);
        }
        $direct->save();

        $gated = $class::find($class::create($start)->id);
        $this->assertTrue($this->user()->approve($this->held($gated, $changes)));

        foreach (array_keys($changes) as $key) {
            $this->assertSame($this->raw($direct, $key), $this->raw($gated, $key), "column $key");
        }
    }

    // Draft and deferred creation

    public function test_a_draft_request_survives_a_nested_save_in_a_created_listener(): void
    {
        EdgeModel::$saveAgainWhenCreated = true;

        $model = DraftEdgeModel::create(['name' => 'n', 'status' => 'gated']);

        $this->assertSame(1, Modification::where('status', 'pending')->count());
        $this->assertTrue($model->getLastModification()->exists);
    }

    public function test_a_failed_draft_insert_doesnt_hide_a_later_ungated_create(): void
    {
        DraftEdgeModel::create(['name' => 'first', 'code' => 'dup']);
        $model = new DraftEdgeModel(['name' => 'second', 'code' => 'dup', 'status' => 'gated']);

        try {
            $model->save();
            $this->fail('the unique index should refuse this');
        } catch (QueryException) {
        }

        $model->status = 'plain';
        $model->code = 'free';
        $model->save();

        $this->assertNotNull(DraftEdgeModel::find($model->id));
        $this->assertSame(0, Modification::count());
    }

    public function test_a_retried_gated_draft_records_one_request(): void
    {
        DraftEdgeModel::create(['name' => 'first', 'code' => 'dup']);
        $model = new DraftEdgeModel(['name' => 'second', 'code' => 'dup', 'status' => 'gated']);

        try {
            $model->save();
        } catch (QueryException) {
        }

        $model->code = 'free';
        $model->save();

        $this->assertNull(DraftEdgeModel::find($model->id));
        $this->assertSame(1, Modification::where('status', 'pending')->count());
    }

    public function test_a_vetoed_delete_keeps_a_rejected_draft_undecided(): void
    {
        $model = DraftEdgeModel::create(['name' => 'n', 'status' => 'gated']);
        $request = $model->getLastModification();
        EdgeModel::$vetoDelete = true;

        $this->assertFalse($this->user()->disapprove($request));

        $this->assertSame('pending', $request->fresh()->status);
        $this->assertNotNull(DraftEdgeModel::withApprovalDrafts()->find($model->id));
    }

    public function test_the_same_deferred_creation_in_another_order_is_the_same_request(): void
    {
        DeferredEdgeModel::create(['name' => 'n', 'status' => 'gated']);
        DeferredEdgeModel::create(['status' => 'gated', 'name' => 'n']);

        $this->assertCount(1, DeferredEdgeModel::pendingCreations());
    }

    public function test_a_deferred_creation_ignores_a_helper_named_like_its_column(): void
    {
        $request = DeferredWithHelperModel::create(['name' => 'n', 'status' => 'gated', 'notes' => 'x'])->getLastModification();

        $this->assertTrue($this->user()->approve($request));

        $this->assertSame('gated', Capsule::table('regression_models')->value('status'));
    }

    public function test_a_deferred_creation_stores_what_a_direct_create_would(): void
    {
        $request = DecimalDeferredModel::create(['name' => 'n', 'status' => 'gated', 'payload' => '10.555'])->getLastModification();
        DecimalDeferredModel::create(['name' => 'direct', 'status' => 'open', 'payload' => '10.555']);

        $this->user()->approve($request);

        $this->assertSame(
            Capsule::table('regression_models')->where('name', 'direct')->value('payload'),
            Capsule::table('regression_models')->where('name', 'n')->value('payload')
        );
    }

    // Custom models

    public function test_a_modification_subclass_with_another_name_can_be_approved(): void
    {
        test_set_config('approvals.models.modification', AuditedModification::class);
        $model = StatusNotesGroupedModel::create(['name' => 'n', 'status' => 'draft']);
        $modification = $this->held($model, ['status' => 'one']);

        $this->assertInstanceOf(AuditedModification::class, $modification);
        $this->assertTrue($this->user()->approve($modification));

        $this->assertSame('one', $this->raw($model, 'status'));
    }

    public function test_an_item_subclass_with_another_name_can_be_approved(): void
    {
        test_set_config('approvals.models.modification_item', AuditedItem::class);
        $model = StatusNotesGroupedModel::create(['name' => 'n', 'status' => 'draft']);
        $modification = $this->held($model, ['status' => 'one']);
        $item = $modification->items()->first();

        $this->assertInstanceOf(AuditedItem::class, $item);
        $this->assertTrue($this->user()->approveItem($item));
        $this->assertSame(1, $item->approvals()->count());

        $this->assertSame('one', $this->raw($model, 'status'));
    }

    public function test_a_soft_deleting_modification_is_decided_once(): void
    {
        test_set_config('approvals.models.modification', SoftModification::class);
        $model = DeleteApprovedModel::create(['name' => 'n', 'status' => 'draft']);
        $modification = $this->held($model, ['status' => 'one']);

        $this->assertTrue($this->user('a')->approve($modification));
        $this->assertSame('one', $this->raw($model, 'status'));

        Capsule::table('regression_models')->where('id', $model->id)->update(['status' => 'newer']);

        $this->assertFalse($this->user('b')->approve($modification));
        $this->assertSame('newer', $this->raw($model, 'status'));
    }

    // Decisions

    public function test_a_global_approval_with_items_is_all_or_nothing(): void
    {
        $model = StatusNotesGroupedModel::create(['name' => 'n', 'status' => 'draft', 'notes' => 'a']);
        $modification = $this->held($model, ['status' => 'one', 'notes' => 'veto me']);
        EdgeModel::$veto = true;

        $this->assertFalse($this->user()->approve($modification));

        $this->assertSame('draft', $this->raw($model, 'status'));
        $this->assertSame('a', $this->raw($model, 'notes'));
        $this->assertSame(['pending', 'pending'], $modification->items()->pluck('status')->all());
        $this->assertSame(0, Approval::count());
    }

    public function test_forcing_doesnt_write_a_rejected_item(): void
    {
        $model = StatusNotesGroupedModel::create(['name' => 'n', 'status' => 'draft', 'notes' => 'a']);
        $modification = $this->held($model, ['status' => 'one', 'notes' => 'b']);
        $this->user()->disapproveItem($modification->items()->where('key', 'status')->first());

        $modification->fresh()->forceApprovalUpdate();

        $this->assertSame('draft', $this->raw($model, 'status'));
        $this->assertSame('b', $this->raw($model, 'notes'));
        $this->assertSame('partially_resolved', $modification->fresh()->status);
    }

    public function test_forcing_a_rejected_change_does_nothing(): void
    {
        $model = EdgeModel::create(['name' => 'n', 'status' => 'draft']);
        $modification = $this->held($model, ['status' => 'one']);
        $this->user()->disapprove($modification);

        $modification->fresh()->forceApprovalUpdate();

        $this->assertSame('draft', $this->raw($model, 'status'));
        $this->assertSame('rejected', $modification->fresh()->status);
    }

    public function test_forcing_with_a_stale_instance_does_nothing(): void
    {
        $model = EdgeModel::create(['name' => 'n', 'status' => 'draft']);
        $modification = $this->held($model, ['status' => 'one']);
        $this->user()->approve($modification);
        Capsule::table('regression_models')->where('id', $model->id)->update(['status' => 'newer']);

        $modification->forceApprovalUpdate();

        $this->assertSame('newer', $this->raw($model, 'status'));
    }

    public function test_forcing_a_direct_audit_row_doesnt_roll_the_model_back(): void
    {
        $model = EdgeModel::find(EdgeModel::create(['name' => 'n', 'status' => 'a'])->id);
        $model->status = 'b';
        $model->save();
        $audit = $model->getLastModification();
        $model->status = 'c';
        $model->save();

        $audit->fresh()->forceApprovalUpdate();

        $this->assertSame('c', $this->raw($model, 'status'));
    }

    public function test_forcing_a_deferred_creation_twice_inserts_one_row(): void
    {
        $request = DeferredEdgeModel::create(['name' => 'n', 'status' => 'gated'])->getLastModification();

        $request->forceApprovalUpdate();
        $request->fresh()->forceApprovalUpdate();

        $this->assertSame(1, Capsule::table('regression_models')->count());
    }

    public function test_forcing_a_change_to_a_deleted_model_does_nothing(): void
    {
        $model = EdgeModel::create(['name' => 'n', 'status' => 'draft']);
        $modification = $this->held($model, ['status' => 'one']);
        $model->delete();

        $modification->fresh()->forceApprovalUpdate();

        $this->assertSame('pending', $modification->fresh()->status);
    }

    public function test_a_gated_restore_can_be_approved(): void
    {
        $model = SoftEdgeModel::create(['name' => 'n']);
        $model->delete();

        $model->gate = true;
        $model->restore();
        $model->gate = false;

        $request = Modification::where('status', 'pending')->firstOrFail();
        $this->assertNotNull($this->raw($model, 'deleted_at'));

        $this->assertTrue($this->user()->approve($request));

        $this->assertNull($this->raw($model, 'deleted_at'));
    }

    public function test_a_full_save_with_groups_decides_only_what_changed(): void
    {
        $model = NotesGroupedModel::find(NotesGroupedModel::create(['name' => 'n', 'notes' => 'a', 'status' => 's'])->id);
        $model->withFullSaveModification();
        $modification = $this->held($model, ['notes' => 'b']);

        $this->assertSame(['notes'], $modification->items()->pluck('key')->all());

        $this->user()->approve($modification);

        $this->assertSame('b', $this->raw($model, 'notes'));
        $this->assertSame('approved', $modification->fresh()->status);
    }

    public function test_a_full_save_with_groups_keeps_a_group_out_when_it_didnt_change(): void
    {
        $model = StatusNotesGroupedModel::find(StatusNotesGroupedModel::create(['name' => 'n', 'notes' => 'a', 'status' => 's'])->id);
        $model->withFullSaveModification();

        $this->assertSame(['notes'], $this->held($model, ['notes' => 'b'])->items()->pluck('key')->all());
    }

    public function test_meta_of_a_repeated_request_doesnt_reach_the_next_one(): void
    {
        $model = EdgeModel::find(EdgeModel::create(['name' => 'n', 'status' => 'draft'])->id);
        $this->held($model, ['status' => 'one']);

        $model->withModificationMeta(['request' => 'repeat']);
        $this->held($model, ['status' => 'one']);

        $this->assertNull($this->held($model, ['status' => 'two'])->fresh()->meta);
    }

    public function test_a_recorded_deletion_isnt_a_creation(): void
    {
        $model = EdgeModel::create(['name' => 'n']);
        $model->delete();

        $model->recordModification('deleted');

        $this->assertCount(0, EdgeModel::creations());
    }

    public function test_migrations_roll_back(): void
    {
        foreach (['2024_01_03_000000_add_approval_lookup_indexes', '2024_01_02_000000_add_flow_and_modification_items'] as $migration) {
            (require __DIR__ . "/../../database/migrations/$migration.php")->down();
        }

        $this->assertFalse(Capsule::schema()->hasColumn('modifications', 'flow'));
        $this->assertFalse(Capsule::schema()->hasColumn('approvals', 'modification_item_id'));
        $this->assertFalse(Capsule::schema()->hasTable('modification_items'));
    }

    // Stored values

    public function test_a_column_named_like_an_eloquent_property_round_trips(): void
    {
        $columns = ['hidden', 'visible', 'table', 'connection', 'with', 'original', 'changes', 'casts', 'relations', 'touches'];

        Capsule::schema()->dropIfExists('colliding_columns');
        Capsule::schema()->create('colliding_columns', function (Blueprint $table) use ($columns) {
            $table->increments('id');
            foreach ($columns as $column) {
                $table->text($column)->nullable();
            }
        });

        foreach ($columns as $column) {
            $model = new CollidingColumnsModel();
            $model->setAttribute($column, 'old');
            $model->save();

            $stored = CollidingColumnsModel::find($model->id);
            $modification = $this->held($stored, [$column => 'new']);

            $this->assertSame(['ori' => 'old', 'mod' => 'new'], $modification->modifications[$column], $column);

            $this->user()->approve($modification);

            $this->assertSame('new', Capsule::table('colliding_columns')->where('id', $model->id)->value($column), $column);
        }
    }

    public function test_user_data_shaped_like_the_binary_marker_stays_data(): void
    {
        foreach ([['__base64' => 'aGVsbG8='], ['__base64' => 5]] as $value) {
            $this->assertApprovalMatchesDirect(ArrayCastModel::class, ['name' => 'n', 'payload' => ['a']], ['payload' => $value]);
        }
    }

    public function test_a_schemaless_sub_key_shaped_like_the_binary_marker_stays_data(): void
    {
        $model = SchemalessModel::find(SchemalessModel::create(['name' => 'n', 'payload' => ['keep' => 1]])->id);
        $modification = $this->held($model, ['payload' => ['keep' => 1, '__base64' => 'aGVsbG8=']]);

        $this->user()->approve($modification);

        $this->assertSame(['keep' => 1, '__base64' => 'aGVsbG8='], json_decode($this->raw($model, 'payload'), true));
    }

    public function test_an_unselected_column_can_be_set_to_null(): void
    {
        $id = EdgeModel::create(['name' => 'n', 'status' => 'live'])->id;
        $model = EdgeModel::select('id', 'name')->find($id);

        $this->user()->approve($this->held($model, ['status' => null]));

        $this->assertNull(Capsule::table('regression_models')->value('status'));
    }

    public function test_a_decimal_isnt_rounded_by_approval(): void
    {
        $this->assertApprovalMatchesDirect(DecimalModel::class, ['name' => 'n', 'payload' => '1.00'], ['payload' => '10.555']);
    }

    public function test_an_integer_cast_isnt_truncated_by_approval(): void
    {
        $this->assertApprovalMatchesDirect(IntegerCastModel::class, ['name' => 'n', 'payload' => 1], ['payload' => '12.7']);
    }

    public function test_a_date_keeps_a_change_of_time_only(): void
    {
        $this->assertApprovalMatchesDirect(DateCastModel::class, ['name' => 'n', 'stamp' => '2026-01-01 10:00:00'], ['stamp' => '2026-01-01 20:00:00']);
    }

    public function test_a_date_with_a_format_keeps_its_time(): void
    {
        $this->assertApprovalMatchesDirect(CustomDateFormatModel::class, ['name' => 'n', 'stamp' => '2026-01-01 10:00:00'], ['stamp' => '2026-02-02 20:00:00']);
    }

    public function test_the_timestamp_cast_given_a_date_object_stores_a_date(): void
    {
        $model = StampModel::create(['name' => 'n', 'stamp' => '2026-01-01 10:00:00']);

        $this->user()->approve($this->held($model, ['stamp' => new \Carbon\Carbon('2026-02-02 11:00:00', 'UTC')]));

        $this->assertSame('2026-02-02 11:00:00', $this->raw($model, 'stamp'));
    }

    public function test_an_explicit_updated_at_set_with_other_changes_is_kept(): void
    {
        $model = EdgeModel::find(EdgeModel::create(['name' => 'n', 'status' => 'draft'])->id);

        $this->user()->approve($this->held($model, ['status' => 'one', 'updated_at' => '2020-01-01 00:00:00']));

        $this->assertSame('2020-01-01 00:00:00', $this->raw($model, 'updated_at'));
    }

    public function test_a_mutator_on_an_array_cast_runs_once(): void
    {
        $this->assertApprovalMatchesDirect(AuditingArrayMutatorModel::class, ['name' => 'n', 'payload' => ['a']], ['payload' => ['x']]);
    }

    public function test_a_getter_returning_an_array_doesnt_break_the_diff_or_the_write(): void
    {
        foreach ([GetterOnlyArrayModel::class, GetterArraySetterStringModel::class] as $class) {
            $direct = $class::find($class::create(['name' => 'n', 'notes' => 'a,b'])->id);
            $direct->notes = 'a,b,c';
            $direct->save();
            $this->assertSame(['ori' => 'a,b', 'mod' => 'a,b,c'], $direct->getLastModification()->modifications['notes'], $class);

            $this->assertApprovalMatchesDirect($class, ['name' => 'n', 'notes' => 'a,b'], ['notes' => 'a,b,c']);
        }
    }

    public function test_a_value_object_extending_fluent_round_trips(): void
    {
        $this->assertApprovalMatchesDirect(AddressModel::class, ['name' => 'n', 'payload' => new Address(['a' => 1, 'b' => 2])], ['payload' => new Address(['a' => 9])]);
    }

    public function test_an_object_with_set_but_no_query_methods_is_stored_whole(): void
    {
        $this->assertApprovalMatchesDirect(SettingsModel::class, ['name' => 'n', 'payload' => new Settings(['a' => 1, 'b' => 2])], ['payload' => new Settings(['a' => 9])]);
    }

    public function test_an_item_writes_the_stored_value_too(): void
    {
        $model = DecimalGroupedModel::create(['name' => 'n', 'payload' => '1.00']);

        $this->user()->approve($this->held($model, ['payload' => '10.555']));

        $this->assertSame('10.555', (string) $this->raw($model, 'payload'));
    }

    public function test_a_plain_change_doesnt_repeat_itself_in_the_diff(): void
    {
        $model = EdgeModel::find(EdgeModel::create(['name' => 'n', 'status' => 'draft'])->id);

        $this->assertSame(['ori' => 'draft', 'mod' => 'one'], $this->held($model, ['status' => 'one'])->modifications['status']);
    }

    public function test_a_direct_audit_row_holds_no_stored_value(): void
    {
        $model = DecimalModel::find(DecimalModel::create(['name' => 'n', 'payload' => '1.00'])->id);
        $model->payload = '10.555';
        $model->save();

        $this->assertArrayNotHasKey('raw', $model->getLastModification()->modifications['payload']);
    }

    public function test_a_raw_sql_expression_cant_be_held(): void
    {
        $model = EdgeModel::find(EdgeModel::create(['name' => 'n', 'price' => 1])->id);

        try {
            $this->held($model, ['price' => new \Illuminate\Database\Query\Expression('price + 5')]);
            $this->fail('holding it would write garbage once approved');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('price', $e->getMessage());
        }

        $this->assertSame(0, Modification::count());
    }

    public function test_a_raw_sql_expression_saved_directly_is_audited_as_sql(): void
    {
        $model = EdgeModel::find(EdgeModel::create(['name' => 'n', 'price' => 1])->id);

        $model->price = new \Illuminate\Database\Query\Expression('price + 5');
        $model->save();

        $this->assertSame(6, (int) $this->raw($model, 'price'));
        $this->assertSame('price + 5', $model->getLastModification()->fresh()->modifications['price']['mod']);
    }

    public function test_a_stringable_object_round_trips(): void
    {
        $this->assertApprovalMatchesDirect(EdgeModel::class, ['name' => 'n', 'notes' => 'a'], ['notes' => new \Illuminate\Support\HtmlString('<b>x</b>')]);
    }

    public function test_an_enum_without_a_cast_is_recorded_by_name(): void
    {
        $model = EdgeModel::find(EdgeModel::create(['name' => 'n'])->id);

        $model->setRawAttributes(['status' => EdgeUnit::Draft] + $model->getAttributes());

        $this->assertSame('Draft', $this->scalarDiffOf($model, 'status'));
    }

    private function scalarDiffOf(Model $model, string $key)
    {
        return (fn () => self::buildModificationDiff($this))->call($model)[$key]['mod'];
    }

    public function test_removing_a_schemaless_key_is_recorded_and_applied(): void
    {
        $model = SchemalessModel::find(SchemalessModel::create(['name' => 'n', 'payload' => ['a' => 1, 'b' => 2]])->id);
        $modification = $this->held($model, ['payload' => ['b' => 2]]);

        $this->assertSame(['ori' => ['a' => 1], 'mod' => [], 'raw' => '{"b":2}'], $modification->modifications['payload']);

        $this->user()->approve($modification);

        $this->assertSame(['b' => 2], json_decode($this->raw($model, 'payload'), true));
    }

    public function test_removing_a_schemaless_key_directly_leaves_an_audit_row(): void
    {
        $model = SchemalessModel::find(SchemalessModel::create(['name' => 'n', 'payload' => ['a' => 1, 'b' => 2]])->id);

        $model->payload = ['b' => 2];
        $model->save();

        $this->assertSame(['ori' => ['a' => 1], 'mod' => []], $model->getLastModification()->modifications['payload']);
    }

    public function test_a_nested_schemaless_removal_is_recorded_and_applied(): void
    {
        $this->assertApprovalMatchesDirect(SchemalessModel::class, ['name' => 'n', 'payload' => ['n' => ['a' => 1, 'b' => 2]]], ['payload' => ['n' => ['b' => 2]]]);
    }

    public function test_a_tiny_float_change_in_a_schemaless_attribute_is_recorded_and_applied(): void
    {
        $this->assertApprovalMatchesDirect(SchemalessModel::class, ['name' => 'n', 'payload' => ['x' => 0.1]], ['payload' => ['x' => 0.1000000000000001]]);
    }

    public function test_a_schemaless_merge_keeps_what_changed_since_the_request(): void
    {
        $model = SchemalessModel::find(SchemalessModel::create(['name' => 'n', 'payload' => ['a' => 1, 'b' => 1]])->id);
        $modification = $this->held($model, ['payload' => ['a' => 2, 'b' => 1]]);

        $this->assertArrayNotHasKey('raw', $modification->modifications['payload']);

        Capsule::table('regression_models')->where('id', $model->id)->update(['payload' => '{"a":1,"b":9}']);
        $this->user()->approve($modification);

        $this->assertSame(['a' => 2, 'b' => 9], json_decode($this->raw($model, 'payload'), true));
    }

    public function test_a_model_deleted_and_saved_again_is_requested_whole(): void
    {
        $model = DeferredEdgeModel::create(['name' => 'n', 'status' => 'open', 'notes' => 'kept']);
        $id = $model->id;
        $model->delete();

        $model->status = 'gated';
        $this->assertFalse($model->save());

        $this->user()->approve($model->getLastModification());

        $row = Capsule::table('regression_models')->where('id', $id)->first();
        $this->assertSame('kept', $row->notes);
        $this->assertSame('n', $row->name);
    }

    public function test_a_listener_saving_a_gated_model_just_created_leaves_it_intact(): void
    {
        $model = new ResaveWhenCreatedModel(['name' => 'n']);
        $model->save();

        $this->assertNotNull($model->id);
        $this->assertTrue($model->exists);
        $this->assertSame('n', $model->name);
        $this->assertSame(1, Capsule::table('regression_models')->count());
        $this->assertSame(['notes'], array_keys($model->getLastModification()->modifications->toArray()));
    }

    public function test_a_listener_saving_during_approval_doesnt_request_the_approved_change_again(): void
    {
        $model = ResaveWhenSavedModel::find(ResaveWhenSavedModel::create(['name' => 'n'])->id);
        $modification = $this->held($model, ['name' => 'approved']);

        ResaveWhenSavedModel::$armed = true;
        $this->user()->approve($modification);

        $this->assertSame('approved', $this->raw($model, 'name'));
        $next = Modification::where('status', 'pending')->firstOrFail();
        $this->assertSame(['notes'], array_keys($next->modifications->toArray()));
    }

    public function test_a_listener_saving_after_an_update_records_each_change_once(): void
    {
        $model = ResaveWhenUpdatedModel::find(ResaveWhenUpdatedModel::create(['name' => 'n'])->id);

        ResaveWhenUpdatedModel::$armed = true;
        $model->name = 'outer';
        $model->save();

        $rows = Modification::orderBy('id')->get()->map(fn ($row) => array_keys($row->modifications->toArray()))->all();
        $this->assertSame([['name'], ['notes']], $rows);
        $this->assertSame('from listener', $this->raw($model, 'notes'));
        $this->assertSame('outer', $this->raw($model, 'name'));
    }

    public function test_a_model_loaded_without_its_key_cant_be_held(): void
    {
        $id = EdgeModel::create(['name' => 'n'])->id;
        $model = EdgeModel::select('name')->find($id);

        $this->expectException(\LogicException::class);

        $this->held($model, ['name' => 'x']);
    }

    public function test_an_enum_case_since_removed_doesnt_stop_a_save(): void
    {
        $model = LegacyEnumModel::create(['name' => 'n']);
        Capsule::table('regression_models')->where('id', $model->id)->update(['status' => 'retired']);
        $model = LegacyEnumModel::find($model->id);

        $model->name = 'x';
        $this->assertTrue($model->save());

        $this->assertSame('x', $this->raw($model, 'name'));
    }

    public function test_an_accessor_that_cant_read_an_unselected_column_doesnt_stop_a_save(): void
    {
        $id = TypedAccessorModel::create(['name' => 'n', 'price' => 1])->id;
        $model = TypedAccessorModel::select('id', 'name')->find($id);

        $model->price = 5;
        $this->assertTrue($model->save());

        $this->assertSame(5, (int) Capsule::table('regression_models')->value('price'));
    }

    public function test_a_veto_during_forcing_leaves_everything_pending(): void
    {
        $model = StatusNotesGroupedModel::create(['name' => 'n', 'status' => 'draft', 'notes' => 'a']);
        $modification = $this->held($model, ['status' => 'one', 'notes' => 'veto me'])->fresh();
        EdgeModel::$veto = true;

        $modification->forceApprovalUpdate();

        $this->assertSame('draft', $this->raw($model, 'status'));
        $this->assertSame('a', $this->raw($model, 'notes'));
        $this->assertSame(['pending', 'pending'], $modification->items()->pluck('status')->all());
        $this->assertSame('pending', $modification->fresh()->status);

        EdgeModel::$veto = false;
        $modification->modifiable->name = 'later';
        $modification->modifiable->save();
        $this->assertSame('a', $this->raw($model, 'notes'), 'what was refused must not leak through the instance');
    }

    public function test_forcing_reads_the_row_rather_than_a_cached_instance(): void
    {
        $model = EdgeModel::create(['name' => 'n', 'status' => 'draft']);
        $modification = $this->held($model, ['status' => 'one']);
        $modification = $modification->fresh();
        $modification->load('modifiable');
        $modification->modifiable->setRawAttributes(['status' => 'one'] + $modification->modifiable->getAttributes(), true);

        $modification->forceApprovalUpdate();

        $this->assertSame('one', $this->raw($model, 'status'));
    }

    public function test_a_failure_while_forcing_restores_the_status_in_memory(): void
    {
        DeferredEdgeModel::create(['name' => 'taken', 'code' => 'dup']);
        $request = DeferredEdgeModel::create(['name' => 'n', 'status' => 'gated', 'code' => 'dup'])->getLastModification();

        try {
            $request->forceApprovalUpdate();
            $this->fail('the unique index should refuse this');
        } catch (QueryException) {
        }

        $this->assertSame('pending', $request->status);
        $this->assertSame('pending', $request->fresh()->status);
    }

    public function test_a_veto_on_a_whole_decision_leaves_no_vote(): void
    {
        $model = EdgeModel::create(['name' => 'n', 'notes' => 'a']);
        $modification = $this->held($model, ['notes' => 'veto me']);
        EdgeModel::$veto = true;

        $this->assertFalse($this->user()->approve($modification));

        $this->assertSame(0, Approval::count());
    }

    public function test_a_veto_on_an_item_leaves_no_vote(): void
    {
        $model = NotesGroupedModel::create(['name' => 'n', 'notes' => 'a']);
        $modification = $this->held($model, ['notes' => 'veto me']);
        EdgeModel::$veto = true;

        $this->assertFalse($this->user()->approveItem($modification->items()->first()));

        $this->assertSame(0, Approval::count());
        $this->assertSame('pending', $modification->fresh()->status);
    }

    public function test_a_vetoed_status_update_undoes_the_write(): void
    {
        test_set_config('approvals.models.modification', VetoingModification::class);
        $model = EdgeModel::create(['name' => 'n', 'status' => 'draft']);
        $modification = $this->held($model, ['status' => 'one']);
        VetoingModification::$vetoResolving = true;

        try {
            $this->assertFalse($this->user()->approve($modification));
        } finally {
            VetoingModification::$vetoResolving = false;
        }

        $this->assertSame('draft', $this->raw($model, 'status'));
        $this->assertSame('pending', $modification->fresh()->status);
    }

    public function test_the_forced_flag_doesnt_outlive_an_early_veto(): void
    {
        $model = EarlyVetoModel::create(['name' => 'n', 'status' => 'draft']);
        $model = EarlyVetoModel::find($model->id);
        $model->gate = true;
        $model->status = 'held';
        $model->save();
        $modification = $model->getLastModification();

        EarlyVetoModel::$vetoFirst = true;
        $model->applyModificationChanges($modification, true);
        EarlyVetoModel::$vetoFirst = false;

        $this->assertFalse($model->isForcedApprovalUpdate());
        $this->assertSame('pending', $modification->fresh()->status);

        $model->status = 'next';
        $this->assertFalse($model->save(), 'the gate still applies');
        $this->assertSame('draft', Capsule::table('regression_models')->value('status'));
    }

    public function test_a_model_on_another_connection_keeps_its_modifications_on_the_default_one(): void
    {
        $manager = Model::getConnectionResolver();
        $config = \Illuminate\Support\Facades\Facade::getFacadeApplication()['config'];
        $connections = $config['database.connections'];
        $connections['other'] = ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''];
        $config['database.connections'] = $connections;
        $manager->connection('other')->getSchemaBuilder()->create('regression_models', function (Blueprint $table) {
            $table->increments('id');
            $table->text('name')->nullable();
            $table->text('status')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        $model = OtherConnectionModel::find(OtherConnectionModel::create(['name' => 'n', 'status' => 'draft'])->id);
        $modification = $this->held($model, ['status' => 'one']);

        $this->assertSame(1, $model->modifications()->count());
        $this->assertTrue($this->user()->approve($modification));

        $this->assertSame('one', $manager->connection('other')->table('regression_models')->value('status'));
    }

    public function test_recording_a_binary_value_doesnt_crash(): void
    {
        $record = EdgeModel::create(['name' => 'n'])->recordModification('signature', [['key' => 'sig', 'proposed' => "\xFE\xFF"]]);

        $this->assertSame(['__base64' => base64_encode("\xFE\xFF")], $record->fresh()->modifications['sig']['mod']);
    }
}

class DecimalDeferredModel extends DeferredEdgeModel
{
    public const CASTS = ['payload' => 'decimal:2'];
}

enum EdgeUnit
{
    case Draft;
}

enum EdgeBacked: string
{
    case Live = 'live';
}

class LegacyEnumModel extends EdgeModel
{
    public const CASTS = ['status' => EdgeBacked::class];
}

class TypedAccessorModel extends EdgeModel
{
    public function getPriceAttribute(int $value): int
    {
        return $value * 2;
    }
}

class ResaveWhenCreatedModel extends EdgeModel
{
    public bool $gate = true;

    protected static function booted(): void
    {
        static::created(function (Model $model) {
            $model->notes = 'from listener';
            $model->save();
        });
    }
}

class ResaveWhenSavedModel extends EdgeModel
{
    public static bool $armed = false;

    protected static function booted(): void
    {
        static::saved(function (Model $model) {
            if (self::$armed) {
                self::$armed = false;
                $model->gate = true;
                $model->notes = 'from listener';
                $model->save();
            }
        });
    }
}

class ResaveWhenUpdatedModel extends EdgeModel
{
    public static bool $armed = false;

    protected static function booted(): void
    {
        static::updated(function (Model $model) {
            if (self::$armed) {
                self::$armed = false;
                $model->notes = 'from listener';
                $model->save();
            }
        });
    }
}

trait VetoesEverySave
{
    public static bool $vetoFirst = false;

    public static function bootVetoesEverySave(): void
    {
        static::saving(fn () => self::$vetoFirst ? false : null);
    }
}

class EarlyVetoModel extends Model
{
    use VetoesEverySave;
    use RequiresApprovalWhenChanges;

    protected $table = 'regression_models';

    protected $guarded = ['id'];

    public bool $gate = false;

    protected function requiresApprovalWhenTrait(array $modifications): bool
    {
        return $this->gate;
    }
}

class VetoingModification extends Modification
{
    public static bool $vetoResolving = false;

    protected static function booted(): void
    {
        parent::booted();

        static::saving(fn (Modification $modification) => self::$vetoResolving && $modification->status !== 'pending' ? false : null);
    }
}

class OtherConnectionModel extends EdgeModel
{
    protected $connection = 'other';
}
