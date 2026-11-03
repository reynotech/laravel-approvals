<?php

declare(strict_types=1);

namespace ReynoTECH\Approvals\Tests\Feature;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Casts\AsEnumArrayObject;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Casts\AsFluent;
use Illuminate\Database\Eloquent\Casts\AsHtmlString;
use Illuminate\Database\Eloquent\Casts\AsStringable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use ReynoTECH\Approvals\Concerns\RequiresApprovalWhenChanges;
use ReynoTECH\Approvals\Models\Modification;
use ReynoTECH\Approvals\Tests\Fixtures\ApproverModel;
use ReynoTECH\Approvals\Tests\TestCase;

trait StoredValueModel
{
    use RequiresApprovalWhenChanges;

    public bool $gate = false;

    protected function requiresApprovalWhenTrait(array $modifications): bool
    {
        return $this->gate;
    }
}

abstract class AbstractStoredBase extends Model
{
    use StoredValueModel;

    protected $table = 'regression_models';

    protected $guarded = ['id'];
}

class ConcreteStoredModel extends AbstractStoredBase
{
    protected $casts = ['payload' => 'array'];
}

class PlainModel extends Model
{
    use StoredValueModel;

    protected $table = 'regression_models';

    protected $guarded = ['id'];
}

class UntrackedNotesModel extends PlainModel
{
    public array $noTrackModification = ['notes'];
}

class SiblingAccessorModel extends Model
{
    use StoredValueModel;

    protected $table = 'regression_models';

    protected $guarded = ['id'];

    protected function price(): Attribute
    {
        return Attribute::make(get: fn ($value, $attributes) => $value . ' ' . $attributes['code']);
    }
}

class SiblingPropertyAccessorModel extends Model
{
    use StoredValueModel;

    protected $table = 'regression_models';

    protected $guarded = ['id'];

    public function getPriceAttribute($value): string
    {
        return $value . ' ' . strtoupper($this->code);
    }
}

class MutatorOnlyModel extends Model
{
    use StoredValueModel;

    protected $table = 'regression_models';

    protected $guarded = ['id'];

    public function setPriceAttribute($value): void
    {
        $this->attributes['price'] = $value * 100;
    }
}

class UppercaseAccessorModel extends Model
{
    use StoredValueModel;

    protected $table = 'regression_models';

    protected $guarded = ['id'];

    protected function name(): Attribute
    {
        return Attribute::make(get: fn ($value) => strtoupper((string) $value));
    }
}

class SymmetricAccessorModel extends Model
{
    use StoredValueModel;

    protected $table = 'regression_models';

    protected $guarded = ['id'];

    protected function payload(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => json_decode((string) $value, true),
            set: fn ($value) => json_encode($value),
        );
    }
}

enum PlainStatus
{
    case Draft;
    case Live;
}

enum BackedStatus: string
{
    case Draft = 'draft';
    case Live = 'live';
}

final class Money
{
    public function __construct(public int $cents)
    {
    }
}

class MoneyCast implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes)
    {
        return $value === null ? null : new Money((int) $value);
    }

    public function set($model, string $key, $value, array $attributes)
    {
        if ($value !== null && ! $value instanceof Money) {
            throw new \InvalidArgumentException('expected Money');
        }

        return $value?->cents;
    }
}

class CastsModel extends Model
{
    use StoredValueModel;

    protected $table = 'regression_models';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return $this->castsUnderTest;
    }

    public array $castsUnderTest = [];

    public function __construct(array $attributes = [])
    {
        $this->castsUnderTest = static::CASTS;

        parent::__construct($attributes);
    }

    protected const CASTS = [];
}

class EncryptedModel extends CastsModel
{
    protected const CASTS = ['secret' => 'encrypted', 'payload' => 'encrypted:array'];
}

class TimestampModel extends CastsModel
{
    protected const CASTS = ['stamp' => 'timestamp'];
}

class StringableModel extends CastsModel
{
    protected const CASTS = ['payload' => AsStringable::class];
}

class HtmlStringModel extends CastsModel
{
    protected const CASTS = ['payload' => AsHtmlString::class];
}

class FluentModel extends CastsModel
{
    protected const CASTS = ['payload' => AsFluent::class];
}

class PlainEnumModel extends CastsModel
{
    protected const CASTS = ['status' => PlainStatus::class];
}

class BackedEnumModel extends CastsModel
{
    protected const CASTS = ['status' => BackedStatus::class];
}

class EnumListModel extends Model
{
    use StoredValueModel;

    protected $table = 'regression_models';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => AsEnumCollection::of(BackedStatus::class)];
    }
}

class ObjectCastModel extends CastsModel
{
    protected const CASTS = ['payload' => 'object'];
}

class DateOnlyModel extends CastsModel
{
    protected const CASTS = ['stamp' => 'date'];
}

class DateTimeModel extends CastsModel
{
    protected const CASTS = ['stamp' => 'datetime'];
}

class BooleanModel extends CastsModel
{
    protected const CASTS = ['status' => 'boolean'];
}

class ArrayModel extends CastsModel
{
    protected const CASTS = ['payload' => 'array'];
}

class AsCollectionModel extends CastsModel
{
    protected const CASTS = ['payload' => AsCollection::class];
}

class PlainEnumListModel extends Model
{
    use StoredValueModel;

    protected $table = 'regression_models';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => AsEnumCollection::of(PlainStatus::class)];
    }
}

class PlainEnumArrayObjectModel extends Model
{
    use StoredValueModel;

    protected $table = 'regression_models';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => AsEnumArrayObject::of(PlainStatus::class)];
    }
}

class MoneyModel extends CastsModel
{
    protected const CASTS = ['price' => MoneyCast::class];
}

final class DiffStoredValueTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Laravel turns warnings into exceptions, and an app sees those.
        set_error_handler(function ($level, $message, $file, $line) {
            throw new \ErrorException($message, 0, $level, $file, $line);
        }, E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        Model::encryptUsing(null);

        parent::tearDown();
    }

    private function raw(Model $model, string $column)
    {
        return Capsule::table('regression_models')->where('id', $model->getKey())->value($column);
    }

    /** Postgres takes bytes only as a stream, and hands them back as one. */
    private function bytes(string $bytes)
    {
        if (getenv('DB_CONNECTION') !== 'pgsql') {
            return $bytes;
        }

        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $bytes);
        rewind($stream);

        return $stream;
    }

    private function rawBytes(Model $model): string
    {
        $value = $this->raw($model, 'blob_value');

        return is_resource($value) ? stream_get_contents($value) : $value;
    }

    private function storedDiff(Modification $modification): string
    {
        return Capsule::table('modifications')->where('id', $modification->id)->value('modifications');
    }

    private function gated(Model $model, array $changes): Modification
    {
        $model->gate = true;

        foreach ($changes as $key => $value) {
            $model->{$key} = $value;
        }

        $model->save();
        $model->gate = false;

        return $model->getLastModification();
    }

    private function approve(Modification $modification): void
    {
        $this->assertTrue(ApproverModel::create(['name' => 'Inspector'])->approve($modification));
    }

    private function fakeEncrypter(): void
    {
        Model::encryptUsing(new class {
            public function encrypt($value, $serialize = true)
            {
                return 'ENC:' . base64_encode($value);
            }

            public function encryptString($value)
            {
                return $this->encrypt($value, false);
            }

            public function decrypt($value, $unserialize = true)
            {
                return base64_decode(substr($value, 4));
            }

            public function decryptString($value)
            {
                return $this->decrypt($value, false);
            }

            public function getKey()
            {
                return 'key';
            }

            public function getAllKeys()
            {
                return ['key'];
            }

            public function getPreviousKeys()
            {
                return [];
            }
        });
    }

    public function test_a_model_on_an_abstract_base_can_be_saved_and_uses_its_own_casts(): void
    {
        $model = ConcreteStoredModel::create(['name' => 'n', 'payload' => ['a']]);

        $modification = $this->gated($model, ['payload' => ['a', 'b']]);
        $this->assertSame(['ori' => ['a'], 'mod' => ['a', 'b'], 'raw' => '["a","b"]'], $modification->modifications['payload']);

        $this->approve($modification);
        $this->assertSame('["a","b"]', $this->raw($model, 'payload'));
    }

    public function test_an_accessor_reading_another_attribute_doesnt_break_the_diff(): void
    {
        foreach ([SiblingAccessorModel::class, SiblingPropertyAccessorModel::class] as $class) {
            $model = $class::find($class::create(['name' => 'n', 'price' => 1, 'code' => "code-$class"])->id);

            $model->price = 2;
            $model->save();

            $this->assertSame(2, (int) $this->raw($model, 'price'));
            $this->assertSame(['ori' => 1, 'mod' => 2], $model->getLastModification()->modifications['price']);
        }
    }

    public function test_a_mutator_runs_once(): void
    {
        $model = MutatorOnlyModel::create(['name' => 'n', 'price' => 1]);

        $modification = $this->gated($model, ['price' => 5]);
        $this->assertSame(['ori' => 100, 'mod' => 500], $modification->modifications['price']);

        $this->approve($modification);
        $this->assertSame(500, (int) $this->raw($model, 'price'));
    }

    public function test_an_accessors_output_isnt_written_to_the_column(): void
    {
        $model = UppercaseAccessorModel::create(['name' => 'old']);

        $this->approve($this->gated($model, ['name' => 'abc']));

        $this->assertSame('abc', $this->raw($model, 'name'));
    }

    public function test_an_accessor_and_mutator_pair_returning_arrays_round_trips(): void
    {
        $model = SymmetricAccessorModel::create(['name' => 'n', 'payload' => ['a' => 1]]);

        $this->approve($this->gated($model, ['payload' => ['a' => 2]]));

        $this->assertSame('{"a":2}', $this->raw($model, 'payload'));
    }

    public function test_encrypted_attributes_stay_encrypted_in_the_diff_and_round_trip(): void
    {
        $this->fakeEncrypter();
        $model = EncryptedModel::create(['name' => 'n', 'secret' => 'old-secret', 'payload' => ['a' => 'old-list']]);

        $modification = $this->gated($model, ['secret' => 'new-secret', 'payload' => ['a' => 'new-list']]);

        foreach (['old-secret', 'new-secret', 'old-list', 'new-list'] as $plain) {
            $this->assertStringNotContainsString($plain, $this->storedDiff($modification));
        }

        $this->approve($modification);

        $fresh = EncryptedModel::find($model->id);
        $this->assertSame('new-secret', $fresh->secret);
        $this->assertSame(['a' => 'new-list'], $fresh->payload);
    }

    public function test_the_timestamp_cast_writes_what_was_proposed(): void
    {
        $model = TimestampModel::create(['name' => 'n', 'stamp' => '2026-01-01 10:00:00']);

        $this->approve($this->gated($model, ['stamp' => '2026-02-02 11:00:00']));

        $this->assertSame('2026-02-02 11:00:00', $this->raw($model, 'stamp'));
    }

    public function test_a_stringable_cast_can_be_saved_and_approved(): void
    {
        $model = StringableModel::find(StringableModel::create(['name' => 'n', 'payload' => 'a'])->id);

        $model->payload = 'b';
        $model->save();
        $this->assertSame('b', $this->raw($model, 'payload'));

        $this->approve($this->gated(StringableModel::find($model->id), ['payload' => 'c']));
        $this->assertSame('c', $this->raw($model, 'payload'));
    }

    public function test_an_html_string_cast_can_be_approved(): void
    {
        if (! class_exists(AsHtmlString::class)) {
            $this->markTestSkipped('AsHtmlString needs Laravel 12.');
        }

        $model = HtmlStringModel::create(['name' => 'n', 'payload' => '<b>a</b>']);

        $this->approve($this->gated($model, ['payload' => '<b>z</b>']));

        $this->assertSame('<b>z</b>', $this->raw($model, 'payload'));
    }

    public function test_a_fluent_cast_is_replaced_whole(): void
    {
        if (! class_exists(AsFluent::class)) {
            $this->markTestSkipped('AsFluent needs Laravel 12.');
        }

        $model = FluentModel::find(FluentModel::create(['name' => 'n', 'payload' => ['a' => 1, 'b' => 2]])->id);

        $modification = $this->gated($model, ['payload' => ['a' => 9]]);
        $this->assertSame(['ori' => ['a' => 1, 'b' => 2], 'mod' => ['a' => 9], 'raw' => '{"a":9}'], $modification->modifications['payload']);

        $this->approve($modification);
        $this->assertSame('{"a":9}', $this->raw($model, 'payload'));
    }

    public function test_a_non_backed_enum_can_be_saved_and_approved(): void
    {
        $model = PlainEnumModel::find(PlainEnumModel::create(['name' => 'n', 'status' => PlainStatus::Draft])->id);

        $modification = $this->gated($model, ['status' => PlainStatus::Live]);
        $this->assertSame(['ori' => 'Draft', 'mod' => 'Live'], $modification->modifications['status']);

        $this->approve($modification);
        $this->assertSame('Live', $this->raw($model, 'status'));
    }

    public function test_a_backed_enum_keeps_its_value(): void
    {
        $model = BackedEnumModel::create(['name' => 'n', 'status' => BackedStatus::Draft]);

        $modification = $this->gated($model, ['status' => BackedStatus::Live]);
        $this->assertSame(['ori' => 'draft', 'mod' => 'live'], $modification->modifications['status']);

        $this->approve($modification);
        $this->assertSame('live', $this->raw($model, 'status'));
    }

    public function test_an_enum_collection_round_trips(): void
    {
        $model = EnumListModel::create(['name' => 'n', 'payload' => [BackedStatus::Draft]]);

        $this->approve($this->gated($model, ['payload' => [BackedStatus::Draft, BackedStatus::Live]]));

        $this->assertSame('["draft","live"]', $this->raw($model, 'payload'));
    }

    public function test_an_object_cast_round_trips(): void
    {
        $model = ObjectCastModel::create(['name' => 'n', 'payload' => (object) ['a' => 1]]);

        $this->approve($this->gated($model, ['payload' => (object) ['a' => 2]]));

        $this->assertSame('{"a":2}', $this->raw($model, 'payload'));
    }

    public function test_a_value_object_cast_round_trips(): void
    {
        $model = MoneyModel::create(['name' => 'n', 'price' => new Money(100)]);

        $modification = $this->gated($model, ['price' => new Money(250)]);
        $this->assertSame(['ori' => 100, 'mod' => 250], $modification->modifications['price']);

        $this->approve($modification);
        $this->assertSame(250, (int) $this->raw($model, 'price'));
    }

    public function test_binary_values_can_be_saved_and_approved(): void
    {
        $bytes = "\xB1\x31\xFF";
        $model = PlainModel::find(PlainModel::create(['name' => 'n', 'blob_value' => 'a'])->id);

        $model->blob_value = $this->bytes($bytes);
        $model->save();
        $this->assertSame($bytes, $this->rawBytes($model));

        $next = "\xFE\x00\xB2";
        $this->approve($this->gated(PlainModel::find($model->id), ['blob_value' => $this->bytes($next)]));
        $this->assertSame($next, $this->rawBytes($model));
    }

    /**
     * @return array<string, array{class-string<Model>, string, mixed, mixed, mixed}>
     */
    public static function chains(): array
    {
        return [
            'date' => [DateOnlyModel::class, 'stamp', '2026-01-01', '2026-02-02', '2026-03-03'],
            'datetime' => [DateTimeModel::class, 'stamp', '2026-01-01 10:00:00', '2026-02-02 11:30:00', '2026-03-03 12:00:00'],
            'boolean' => [BooleanModel::class, 'status', true, false, null],
            'object' => [ObjectCastModel::class, 'payload', (object) ['a' => 1], (object) ['a' => 2], (object) ['a' => 3]],
            'array holding a scalar' => [ArrayModel::class, 'payload', 'text', ['a'], ['b']],
            'collection cleared and set' => [AsCollectionModel::class, 'payload', ['a'], null, ['b']],
            'plain' => [PlainModel::class, 'status', 'a', 'b', 'c'],
        ];
    }

    /**
     * @dataProvider chains
     */
    public function test_ori_and_mod_write_the_same_value_the_same_way($class, string $key, $first, $second, $third): void
    {
        $model = new $class();
        $model->{$key} = $first;
        $model->save();
        $model = $class::find($model->id);

        $model->{$key} = $second;
        $model->save();
        $step = $model->getLastModification()->fresh()->modifications[$key];

        $model = $class::find($model->id);
        $model->{$key} = $third;
        $model->save();
        $next = $model->getLastModification()->fresh()->modifications[$key];

        $this->assertSame($step['mod'], $next['ori'], 'the value after a change reads the same as the value before the next one');

        $model = $class::find($model->id);
        $model->{$key} = $first;
        $model->save();
        $back = $model->getLastModification()->fresh()->modifications[$key];

        $this->assertSame($step['ori'], $back['mod'], 'reverting proposes what the first change recorded as the original');
    }

    public function test_a_date_keeps_one_format_on_both_sides(): void
    {
        $model = DateOnlyModel::find(DateOnlyModel::create(['name' => 'n', 'stamp' => '2026-01-01'])->id);

        $model->stamp = '2026-02-02';
        $model->save();

        $this->assertSame(['ori' => '2026-01-01', 'mod' => '2026-02-02'], $model->getLastModification()->modifications['stamp']);
    }

    /**
     * @return array<string, array{array<string, mixed>, array<string, mixed>}>
     */
    public static function arrayChanges(): array
    {
        return [
            'tiny float' => [['x' => 0.1], ['x' => 0.1000000000000001]],
            'very small floats' => [['x' => 1e-20], ['x' => 2e-20]],
            'map key order' => [['x' => 1, 'y' => 2], ['y' => 2, 'x' => 1]],
            'nested key order' => [['n' => ['x' => 1, 'y' => 2]], ['n' => ['y' => 2, 'x' => 1]]],
        ];
    }

    /**
     * @dataProvider arrayChanges
     */
    public function test_a_change_too_small_for_the_differ_is_still_a_change(array $before, array $after): void
    {
        $direct = ArrayModel::find(ArrayModel::create(['name' => 'n', 'payload' => $before])->id);
        $direct->payload = $after;
        $direct->save();

        $this->assertSame(['ori' => $before, 'mod' => $after], $direct->getLastModification()->modifications['payload']);

        $gated = ArrayModel::find(ArrayModel::create(['name' => 'n', 'payload' => $before])->id);
        $modification = $this->gated($gated, ['payload' => $after]);
        $this->approve($modification);

        $this->assertSame($this->raw($direct, 'payload'), $this->raw($gated, 'payload'));
    }

    public function test_an_array_saved_unchanged_records_nothing(): void
    {
        $model = ArrayModel::find(ArrayModel::create(['name' => 'n', 'payload' => ['x' => 1]])->id);

        $model->payload = ['x' => 1];
        $model->save();

        $this->assertSame(0, Modification::count());
    }

    /**
     * @return array<string, array{class-string<Model>}>
     */
    public static function plainEnumLists(): array
    {
        return ['collection' => [PlainEnumListModel::class], 'array object' => [PlainEnumArrayObjectModel::class]];
    }

    /**
     * @dataProvider plainEnumLists
     */
    public function test_non_backed_enums_inside_a_collection_can_be_saved_and_approved(string $class): void
    {
        $model = $class::find($class::create(['name' => 'n', 'payload' => [PlainStatus::Draft]])->id);

        $model->payload = [PlainStatus::Draft, PlainStatus::Live];
        $model->save();
        $this->assertSame(
            ['ori' => ['Draft'], 'mod' => ['Draft', 'Live']],
            $model->getLastModification()->modifications['payload']
        );

        $gated = $class::find($class::create(['name' => 'n', 'payload' => [PlainStatus::Draft]])->id);
        $this->approve($this->gated($gated, ['payload' => [PlainStatus::Live]]));
        $this->assertSame('["Live"]', $this->raw($gated, 'payload'));

        $fromNull = $class::create(['name' => 'n']);
        $this->approve($this->gated($class::find($fromNull->id), ['payload' => [PlainStatus::Live]]));
        $this->assertSame('["Live"]', $this->raw($fromNull, 'payload'));
    }

    public function test_an_empty_object_stays_an_object(): void
    {
        $model = ObjectCastModel::create(['name' => 'n', 'payload' => (object) ['a' => 1]]);

        $this->approve($this->gated($model, ['payload' => new \stdClass()]));

        $this->assertSame('{}', $this->raw($model, 'payload'));
        $this->assertInstanceOf(\stdClass::class, ObjectCastModel::find($model->id)->payload);
    }

    public function test_a_gated_change_leaves_the_instance_as_stored(): void
    {
        $model = PlainModel::find(PlainModel::create(['name' => 'n', 'status' => 'draft'])->id);

        $this->gated($model, ['status' => 'one']);

        $this->assertSame('draft', $model->status);
        $this->assertFalse($model->isDirty());
    }

    public function test_a_gated_change_on_a_cast_leaves_the_instance_as_stored(): void
    {
        $model = ArrayModel::find(ArrayModel::create(['name' => 'n', 'payload' => ['a']])->id);

        $this->gated($model, ['payload' => ['a', 'b']]);

        $this->assertSame(['a'], $model->payload);
        $this->assertFalse($model->isDirty());
    }

    public function test_a_later_gated_save_doesnt_carry_an_earlier_change(): void
    {
        $model = PlainModel::find(PlainModel::create(['name' => 'n', 'status' => 'draft'])->id);

        $first = $this->gated($model, ['status' => 'one']);
        $second = $this->gated($model, ['name' => 'other']);

        $this->assertSame(['status'], array_keys($first->modifications->toArray()));
        $this->assertSame(['name'], array_keys($second->modifications->toArray()));

        $this->assertTrue(ApproverModel::create(['name' => 'a'])->disapprove($first));
        $this->approve($second);

        $fresh = PlainModel::find($model->id);
        $this->assertSame('draft', $fresh->status, 'a rejected change must not reach the column through a later one');
        $this->assertSame('other', $fresh->name);
    }

    public function test_a_later_direct_save_doesnt_carry_a_held_change(): void
    {
        $model = PlainModel::find(PlainModel::create(['name' => 'n', 'status' => 'draft'])->id);

        $this->gated($model, ['status' => 'one']);

        $model->name = 'other';
        $model->save();

        $fresh = PlainModel::find($model->id);
        $this->assertSame('draft', $fresh->status, 'a held change must not be written without approval');
        $this->assertSame('other', $fresh->name);
        $this->assertSame(['name'], array_keys($model->getLastModification()->modifications->toArray()));
    }

    public function test_repeating_a_held_change_keeps_one_pending_row(): void
    {
        $model = PlainModel::find(PlainModel::create(['name' => 'n', 'status' => 'draft'])->id);

        $first = $this->gated($model, ['status' => 'one']);
        $second = $this->gated($model, ['status' => 'one']);

        $this->assertTrue($first->is($second));
        $this->assertSame(1, Modification::count());
    }

    public function test_a_held_untracked_column_is_undone_in_memory_too(): void
    {
        $model = UntrackedNotesModel::find(UntrackedNotesModel::create(['name' => 'n', 'notes' => 'a', 'status' => 's'])->id);

        $this->gated($model, ['status' => 't', 'notes' => 'b']);

        $this->assertSame('a', $model->notes);
        $this->assertSame('s', $model->status);
    }

    public function test_a_gated_save_with_nothing_dirty_creates_nothing(): void
    {
        $model = PlainModel::find(PlainModel::create(['name' => 'n'])->id);
        $model->gate = true;

        $this->assertTrue($model->save());

        $this->assertSame(0, Modification::count());
    }

    public function test_touch_is_neither_gated_nor_audited(): void
    {
        $model = PlainModel::find(PlainModel::create(['name' => 'n'])->id);
        $model->gate = true;

        $this->assertTrue($model->touch());

        $model->gate = false;
        $model->touch();

        $this->assertSame(0, Modification::count());
    }

    public function test_changing_only_untracked_columns_saves_directly(): void
    {
        $model = UntrackedNotesModel::find(UntrackedNotesModel::create(['name' => 'n', 'notes' => 'a'])->id);
        $model->gate = true;
        $model->notes = 'b';

        $this->assertTrue($model->save());

        $this->assertSame('b', $this->raw($model, 'notes'));
        $this->assertSame(0, Modification::count());
    }

    public function test_untracked_columns_changed_with_tracked_ones_survive_approval(): void
    {
        $model = UntrackedNotesModel::find(UntrackedNotesModel::create(['name' => 'n', 'notes' => 'a', 'status' => 's'])->id);

        $this->approve($this->gated($model, ['status' => 't', 'notes' => 'b']));

        $this->assertSame('t', $this->raw($model, 'status'));
        $this->assertSame('b', $this->raw($model, 'notes'));
    }

    public function test_untracked_columns_stay_out_of_the_audit_trail(): void
    {
        $model = UntrackedNotesModel::find(UntrackedNotesModel::create(['name' => 'n', 'notes' => 'a', 'status' => 's'])->id);

        $model->status = 't';
        $model->notes = 'b';
        $model->save();

        $this->assertSame(['status'], array_keys($model->getLastModification()->modifications->toArray()));
    }

    public function test_approving_a_full_save_leaves_newer_changes_alone(): void
    {
        $model = PlainModel::find(PlainModel::create(['name' => 'n', 'status' => 'draft', 'notes' => 'a'])->id);
        $model->withFullSaveModification();
        $modification = $this->gated($model, ['status' => 'one']);

        $this->assertArrayHasKey('notes', $modification->modifications->toArray());

        $newer = PlainModel::find($model->id);
        $newer->notes = 'newer';
        $newer->save();

        $this->approve($modification);

        $fresh = PlainModel::find($model->id);
        $this->assertSame('one', $fresh->status);
        $this->assertSame('newer', $fresh->notes);
    }

    public function test_a_full_save_keeps_changed_columns_that_werent_selected(): void
    {
        $id = PlainModel::create(['name' => 'n', 'status' => 'draft'])->id;
        $model = PlainModel::select('id', 'name')->find($id);
        $model->withFullSaveModification();

        $this->approve($this->gated($model, ['status' => 'one']));

        $this->assertSame('one', PlainModel::find($id)->status);
    }

    public function test_a_full_save_of_changes_only_with_nothing_dirty_creates_nothing(): void
    {
        $model = PlainModel::find(PlainModel::create(['name' => 'n'])->id);
        $model->withFullSaveOnlyUpdateModification();
        $model->gate = true;

        $this->assertTrue($model->save());

        $this->assertSame(0, Modification::count());
    }
}
