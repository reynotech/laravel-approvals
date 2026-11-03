<?php

declare(strict_types=1);

namespace ReynoTECH\Approvals\Tests\Feature;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Casts\AsEncryptedArrayObject;
use Illuminate\Database\Eloquent\Casts\AsEncryptedCollection;
use Illuminate\Database\Eloquent\Casts\AsEnumArrayObject;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Casts\AsFluent;
use Illuminate\Database\Eloquent\Casts\AsHtmlString;
use Illuminate\Database\Eloquent\Casts\AsStringable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;
use ReynoTECH\Approvals\Concerns\RequiresApprovalWhenChanges;
use ReynoTECH\Approvals\Models\Modification;
use ReynoTECH\Approvals\Tests\Fixtures\ApproverModel;
use ReynoTECH\Approvals\Tests\TestCase;
use Spatie\SchemalessAttributes\Casts\SchemalessAttributes;

enum CoverageStatus: string
{
    case Draft = 'draft';
    case Live = 'live';
}

enum CoveragePlain
{
    case Draft;
    case Live;
}

class CastCoverageModel extends Model
{
    use RequiresApprovalWhenChanges;

    public static $cast = null;

    public static bool $grouped = false;

    public bool $gate = false;

    protected $table = 'cast_coverage';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return self::$cast ? ['value' => self::$cast] : [];
    }

    protected function requiresApprovalWhenTrait(array $modifications): bool
    {
        return $this->gate;
    }

    protected function approvableItemGroups(array $dirtyKeys): array
    {
        return self::$grouped ? ['value' => ['value']] : [];
    }
}

/**
 * Every cast Eloquent ships, checked by the same promise: approving a change, whole or by item,
 * stores exactly what saving it directly would have, and a real change always leaves a diff row.
 * Not covered: AsBinary and AsUri, which need packages this one doesn't depend on, and AsVector on
 * MariaDB, where it stores a raw SQL expression that can't be held.
 */
final class EloquentCastCoverageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        set_error_handler(function ($level, $message, $file, $line) {
            throw new \ErrorException($message, 0, $level, $file, $line);
        }, E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

        $encrypter = new class {
            public function encrypt($value, $serialize = true)
            {
                return 'ENC:' . base64_encode($serialize ? serialize($value) : $value);
            }

            public function encryptString($value)
            {
                return $this->encrypt($value, false);
            }

            public function decrypt($value, $unserialize = true)
            {
                $decoded = base64_decode(substr($value, 4));

                return $unserialize ? unserialize($decoded) : $decoded;
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
        };

        Model::encryptUsing($encrypter);
        Facade::getFacadeApplication()->instance('encrypter', $encrypter);
        Facade::getFacadeApplication()->instance('hash', new class {
            public function isHashed($value)
            {
                return str_starts_with((string) $value, 'H$');
            }

            public function make($value, $options = [])
            {
                return 'H$' . $value;
            }

            public function verifyConfiguration($value)
            {
                return true;
            }
        });
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        Model::encryptUsing(null);
        CastCoverageModel::$grouped = false;

        parent::tearDown();
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function arrayCasts(): array
    {
        $casts = [
            'array' => ['array'],
            'json' => ['json'],
            'collection' => ['collection'],
            'encrypted:array' => ['encrypted:array'],
            'encrypted:collection' => ['encrypted:collection'],
            'encrypted:json' => ['encrypted:json'],
            'AsArrayObject' => [AsArrayObject::class],
            'AsCollection' => [AsCollection::class],
            'AsCollection::using' => [AsCollection::using(Collection::class)],
            'AsEncryptedArrayObject' => [AsEncryptedArrayObject::class],
            'AsEncryptedCollection' => [AsEncryptedCollection::class],
            'schemaless' => [SchemalessAttributes::class],
        ];

        if (class_exists(AsFluent::class)) {
            $casts['AsFluent'] = [AsFluent::class];
        }

        if (in_array('json:unicode', (new \ReflectionProperty(Model::class, 'primitiveCastTypes'))->getValue(), true)) {
            $casts['json:unicode'] = ['json:unicode'];
        }

        return $casts;
    }

    /**
     * @return array<string, array{mixed, mixed}>
     */
    private static function arrayChanges(): array
    {
        return [
            'edit a key' => [['a' => 1, 'b' => 2], ['a' => 9, 'b' => 2]],
            'add a key' => [['a' => 1], ['a' => 1, 'b' => 2]],
            'remove a key' => [['a' => 1, 'b' => 2], ['b' => 2]],
            'remove every key' => [['a' => 1], []],
            'add to a list' => [['x', 'y'], ['x', 'y', 'w']],
            'remove from a list' => [['x', 'y', 'w'], ['x', 'w']],
            'reorder a list' => [['x', 'y'], ['y', 'x']],
            'reorder a map' => [['a' => 1, 'b' => 2], ['b' => 2, 'a' => 1]],
            'edit nested' => [['n' => ['a' => 1, 'b' => 2]], ['n' => ['a' => 9, 'b' => 2]]],
            'remove nested' => [['n' => ['a' => 1, 'b' => 2]], ['n' => ['b' => 2]]],
            'empty nested' => [['n' => ['a' => 1]], ['n' => []]],
            'null inside' => [['a' => 1], ['a' => null]],
            'string to number' => [['a' => '1'], ['a' => 1]],
            'tiny float' => [['a' => 0.1], ['a' => 0.1000000000000001]],
        ];
    }

    /**
     * @dataProvider arrayCasts
     */
    public function test_array_casts_round_trip_every_change($cast): void
    {
        foreach (self::arrayChanges() as $label => [$from, $to]) {
            $this->assertRoundTrips($cast, 'text', $from, $to, $label);
        }
    }

    /**
     * @dataProvider arrayCasts
     */
    public function test_array_casts_can_be_cleared_and_set_from_null($cast): void
    {
        if ($cast === SchemalessAttributes::class) {
            $this->assertRoundTrips($cast, 'text', ['a' => 1], [], 'to empty');

            return;
        }

        $this->assertRoundTrips($cast, 'text', ['a' => 1], null, 'to null');
        $this->assertRoundTrips($cast, 'text', null, ['a' => 1], 'from null');
    }

    /**
     * @dataProvider arrayCasts
     */
    public function test_array_casts_record_a_removed_key_when_saved_directly($cast): void
    {
        $this->useCast($cast, 'text');
        $model = $this->stored(['a' => 1, 'b' => 2]);

        $model->value = ['b' => 2];
        $model->save();

        $row = $model->getLastModification()?->fresh()->modifications['value'] ?? null;
        $this->assertNotNull($row, 'a removal must leave an audit row');
        $this->assertNotEquals($row['ori'], $row['mod']);
    }

    /**
     * @return array<string, array{mixed, string, mixed, mixed}>
     */
    public static function scalarCasts(): array
    {
        $dates = ['2026-01-01', '2026-02-02'];
        $times = ['2026-01-01 10:00:00', '2026-02-02 11:30:00'];

        $casts = [
            'no cast' => [null, 'text', 'a', 'b'],
            'string' => ['string', 'text', 'a', 'b'],
            'int' => ['int', 'integer', 1, 2],
            'integer' => ['integer', 'integer', 1, 2],
            'real' => ['real', 'float', 1.5, 2.5],
            'float' => ['float', 'float', 1.5, 2.5],
            'double' => ['double', 'float', 1.5, 2.5],
            'decimal' => ['decimal:2', 'text', '1.00', '10.555'],
            'bool' => ['bool', 'boolean', true, false],
            'boolean' => ['boolean', 'boolean', false, true],
            'date' => ['date', 'date', ...$dates],
            'date with a time' => ['date', 'dateTime', ...$times],
            'datetime' => ['datetime', 'dateTime', ...$times],
            'date with a format' => ['date:d/m/Y', 'date', ...$dates],
            'datetime with a format' => ['datetime:d/m/Y', 'dateTime', ...$times],
            'immutable_date' => ['immutable_date', 'date', ...$dates],
            'immutable_datetime' => ['immutable_datetime', 'dateTime', ...$times],
            'immutable_date with a format' => ['immutable_date:d/m/Y', 'date', ...$dates],
            'immutable_datetime with a format' => ['immutable_datetime:d/m/Y', 'dateTime', ...$times],
            'timestamp' => ['timestamp', 'integer', 1700000000, 1700000100],
            'encrypted' => ['encrypted', 'text', 'a', 'b'],
            'hashed' => ['hashed', 'text', 'p1', 'p2'],
            'backed enum' => [CoverageStatus::class, 'string', CoverageStatus::Draft, CoverageStatus::Live],
            'plain enum' => [CoveragePlain::class, 'string', CoveragePlain::Draft, CoveragePlain::Live],
            'AsStringable' => [AsStringable::class, 'text', 'a', 'b'],
            'object' => ['object', 'text', (object) ['a' => 1, 'b' => 2], (object) ['b' => 2]],
            'encrypted:object' => ['encrypted:object', 'text', (object) ['a' => 1, 'b' => 2], (object) ['b' => 2]],
            'empty object' => ['object', 'text', (object) ['a' => 1], new \stdClass()],
        ];

        if (class_exists(AsHtmlString::class)) {
            $casts['AsHtmlString'] = [AsHtmlString::class, 'text', '<b>a</b>', '<b>z</b>'];
        }

        return $casts;
    }

    /**
     * @dataProvider scalarCasts
     */
    public function test_scalar_casts_round_trip($cast, string $column, $from, $to): void
    {
        $this->assertRoundTrips($cast, $column, $from, $to, 'change');
        $this->assertRoundTrips($cast, $column, $from, null, 'to null');
        $this->assertRoundTrips($cast, $column, null, $to, 'from null');
    }

    /**
     * @return array<string, array{string, array, array}>
     */
    public static function enumListCasts(): array
    {
        return [
            'AsEnumCollection backed' => [AsEnumCollection::of(CoverageStatus::class), [CoverageStatus::Draft, CoverageStatus::Live], [CoverageStatus::Live]],
            'AsEnumCollection plain' => [AsEnumCollection::of(CoveragePlain::class), [CoveragePlain::Draft, CoveragePlain::Live], [CoveragePlain::Live]],
            'AsEnumArrayObject backed' => [AsEnumArrayObject::of(CoverageStatus::class), [CoverageStatus::Draft, CoverageStatus::Live], [CoverageStatus::Live]],
            'AsEnumArrayObject plain' => [AsEnumArrayObject::of(CoveragePlain::class), [CoveragePlain::Draft, CoveragePlain::Live], [CoveragePlain::Live]],
        ];
    }

    /**
     * @dataProvider enumListCasts
     */
    public function test_enum_list_casts_round_trip(string $cast, array $from, array $to): void
    {
        $this->assertRoundTrips($cast, 'text', $from, $to, 'remove');
        $this->assertRoundTrips($cast, 'text', $to, $from, 'add');
    }

    public function test_a_vector_round_trips(): void
    {
        if (! class_exists(\Illuminate\Database\Eloquent\Casts\AsVector::class)) {
            $this->markTestSkipped('AsVector needs a newer Laravel.');
        }

        $this->assertRoundTrips(\Illuminate\Database\Eloquent\Casts\AsVector::class, 'text', [1.5, 2.5], [1.5, 3.5], 'change');
        $this->assertRoundTrips(\Illuminate\Database\Eloquent\Casts\AsVector::class, 'text', [1.5, 2.5], null, 'to null');
    }

    private function useCast($cast, string $column): void
    {
        CastCoverageModel::$cast = $cast;

        Capsule::schema()->dropIfExists('cast_coverage');
        Capsule::schema()->create('cast_coverage', function (Blueprint $table) use ($column) {
            $table->increments('id');
            $table->{$column}('value')->nullable();
            $table->timestamps();
        });
    }

    private function stored($value): CastCoverageModel
    {
        $model = new CastCoverageModel();
        $model->value = $value;
        $model->save();

        return CastCoverageModel::find($model->id);
    }

    private function raw(Model $model)
    {
        return Capsule::table('cast_coverage')->where('id', $model->getKey())->value('value');
    }

    private function assertRoundTrips($cast, string $column, $from, $to, string $label): void
    {
        $this->useCast($cast, $column);
        $approver = ApproverModel::firstOrCreate(['name' => 'Inspector']);

        $direct = $this->stored($from);
        $before = $this->raw($direct);
        $direct->value = $to;
        $direct->save();
        $expected = $this->raw($direct);

        $changed = $before !== $expected;

        if ($changed) {
            $this->assertNotNull($direct->getLastModification(), "$label: direct save left no audit row");
        }

        foreach ([false, true] as $grouped) {
            CastCoverageModel::$grouped = $grouped;
            $how = $grouped ? 'item' : 'whole';

            $gated = $this->stored($from);
            $gated->gate = true;
            $gated->value = $to;
            $gated->save();

            if (! $changed) {
                continue;
            }

            $this->assertSame($before, $this->raw($gated), "$label ($how): written before approval");

            $modification = $gated->getLastModification();
            $this->assertArrayHasKey('value', $modification->fresh()->modifications->toArray(), "$label ($how): empty diff");

            $this->assertTrue($approver->approve($modification), "$label ($how): approval refused");
            $this->assertSame($expected, $this->raw($gated), "$label ($how)");
            $this->assertSame('approved', Modification::find($modification->id)->status, "$label ($how)");
        }

        CastCoverageModel::$grouped = false;
    }
}
