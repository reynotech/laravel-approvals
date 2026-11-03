<?php

declare(strict_types=1);

namespace ReynoTECH\Approvals\Tests\Feature;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use ReynoTECH\Approvals\Concerns\RequiresApprovalWhenChanges;
use ReynoTECH\Approvals\Models\Approval;
use ReynoTECH\Approvals\Models\Modification;
use ReynoTECH\Approvals\Tests\Fixtures\ApproverModel;
use ReynoTECH\Approvals\Tests\TestCase;

class TypedColumnsModel extends Model
{
    use RequiresApprovalWhenChanges;

    public static array $castsUnderTest = [];

    protected $table = 'typed_columns';

    protected $guarded = ['id'];

    public bool $gate = false;

    protected function casts(): array
    {
        return self::$castsUnderTest;
    }

    protected function requiresApprovalWhenTrait(array $modifications): bool
    {
        return $this->gate;
    }
}

/**
 * What a real server does and SQLite doesn't: typed columns, refusing bad values, and `json` columns
 * that normalize what they store. Approving must still reach what saving directly would have.
 */
final class ServerColumnTypesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! self::usesServer()) {
            $this->markTestSkipped('Needs a real server: run it with bin/bench test mysql or pgsql.');
        }

        Capsule::schema()->create('typed_columns', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('short', 5)->nullable();
            $table->json('doc')->nullable();
            $table->date('day')->nullable();
            $table->dateTime('moment')->nullable();
            $table->timestamp('stamped')->nullable();
            $table->boolean('flag')->nullable();
            $table->decimal('amount', 10, 2)->nullable();
            $table->unsignedInteger('quantity')->nullable();
            $table->enum('choice', ['a', 'b'])->nullable();
            $table->float('ratio')->nullable();
            $table->binary('bytes')->nullable();
            $table->timestamps();
        });
    }

    private function row(int $id, string $column)
    {
        $value = Capsule::table('typed_columns')->where('id', $id)->value($column);

        // Postgres hands `bytea` back as a stream.
        return is_resource($value) ? stream_get_contents($value) : $value;
    }

    /** Postgres takes bytes only as a stream, and each save uses one up. */
    private function value(string $column, $value)
    {
        if ($column !== 'bytes' || getenv('DB_CONNECTION') !== 'pgsql') {
            return $value;
        }

        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $value);
        rewind($stream);

        return $stream;
    }

    private function seed(string $column, $value): TypedColumnsModel
    {
        $model = new TypedColumnsModel();
        $model->{$column} = $this->value($column, $value);
        $model->save();

        return TypedColumnsModel::find($model->id);
    }

    private function hold(TypedColumnsModel $model, string $column, $value): Modification
    {
        $model->gate = true;
        $model->{$column} = $this->value($column, $value);
        $model->save();

        return $model->getLastModification();
    }

    /**
     * @return array<string, array{array<string, string>, string, mixed, mixed}>
     */
    public static function typedChanges(): array
    {
        return [
            'json map' => [['doc' => 'array'], 'doc', ['a' => 1, 'b' => 2], ['b' => 2]],
            'json nested' => [['doc' => 'array'], 'doc', ['n' => ['a' => 1, 'b' => 2]], ['n' => ['b' => 3]]],
            'json list' => [['doc' => 'array'], 'doc', ['x', 'y'], ['y', 'x']],
            'json big numbers' => [['doc' => 'array'], 'doc', ['n' => 1], ['n' => 12345678901234567]],
            'json float' => [['doc' => 'array'], 'doc', ['n' => 1.5], ['n' => 0.1000000000000001]],
            'json unicode' => [['doc' => 'array'], 'doc', ['n' => 'a'], ['n' => 'año € 🙂']],
            'json collection' => [['doc' => 'collection'], 'doc', ['a' => 1], ['a' => 2]],
            'json object' => [['doc' => 'object'], 'doc', (object) ['a' => 1], (object) ['a' => 2]],
            'json empty object' => [['doc' => 'object'], 'doc', (object) ['a' => 1], new \stdClass()],
            'date' => [['day' => 'date'], 'day', '2026-01-01', '2026-02-02'],
            'date with a time' => [['day' => 'date'], 'day', '2026-01-01', '2026-02-02 20:00:00'],
            'datetime' => [['moment' => 'datetime'], 'moment', '2026-01-01 10:00:00', '2026-02-02 11:30:00'],
            'datetime with only the time changing' => [['moment' => 'datetime'], 'moment', '2026-01-01 10:00:00', '2026-01-01 10:00:01'],
            'datetime with a format' => [['moment' => 'datetime:d/m/Y'], 'moment', '2026-01-01 10:00:00', '2026-02-02 11:30:00'],
            'timestamp column' => [['stamped' => 'datetime'], 'stamped', '2026-01-01 10:00:00', '2026-02-02 11:30:00'],
            'boolean' => [['flag' => 'boolean'], 'flag', true, false],
            'decimal' => [['amount' => 'decimal:2'], 'amount', '10.50', '10.60'],
            'decimal rounded by the column' => [['amount' => 'decimal:2'], 'amount', '10.50', '10.555'],
            // Only MySQL has unsigned columns, which go past the signed limit.
            'large integer' => [['quantity' => 'integer'], 'quantity', 1, getenv('DB_CONNECTION') === 'mysql' ? 4000000000 : 2000000000],
            'enum column' => [[], 'choice', 'a', 'b'],
            'float' => [['ratio' => 'float'], 'ratio', 1.5, 2.25],
            'binary' => [[], 'bytes', "\x01\x02", "\xFE\x00\xFF"],
            'uncasted json' => [[], 'doc', '{"a": 1}', '{"a": 2}'],
        ];
    }

    /**
     * @dataProvider typedChanges
     */
    public function test_approving_stores_what_a_direct_save_would($casts, string $column, $from, $to): void
    {
        TypedColumnsModel::$castsUnderTest = $casts;

        $direct = $this->seed($column, $from);
        $direct->{$column} = $this->value($column, $to);
        $direct->save();
        $expected = $this->row($direct->id, $column);
        $this->assertNotNull($direct->getLastModification(), 'a direct change leaves an audit row');

        $gated = $this->seed($column, $from);
        $before = $this->row($gated->id, $column);
        $modification = $this->hold($gated, $column, $to);
        $this->assertSame($before, $this->row($gated->id, $column), 'nothing written before approval');

        $this->assertTrue(ApproverModel::create(['name' => 'u'])->approve($modification->fresh()));

        $this->assertSame($expected, $this->row($gated->id, $column), 'approved vs direct');

        // Read back, the diff must keep working: a second change sees the first as its original.
        $next = TypedColumnsModel::find($gated->id);
        $this->assertFalse($next->isDirty());
    }

    /**
     * @dataProvider typedChanges
     */
    public function test_the_diff_survives_being_stored_and_read_back($casts, string $column, $from, $to): void
    {
        TypedColumnsModel::$castsUnderTest = $casts;

        $modification = $this->hold($this->seed($column, $from), $column, $to);

        $stored = Modification::find($modification->id)->modifications->toArray();

        $this->assertArrayHasKey($column, $stored);
        $this->assertArrayHasKey('ori', $stored[$column]);
        $this->assertArrayHasKey('mod', $stored[$column]);
    }

    public function test_a_json_change_of_key_order_only_writes_and_decides_cleanly(): void
    {
        TypedColumnsModel::$castsUnderTest = ['doc' => 'array'];

        $model = $this->seed('doc', ['a' => 1, 'b' => 2]);
        $modification = $this->hold($model, 'doc', ['b' => 2, 'a' => 1]);

        $this->assertTrue(ApproverModel::create(['name' => 'u'])->approve($modification->fresh()));

        $this->assertSame('approved', $modification->fresh()->status);
        $this->assertDiffSame(['a' => 1, 'b' => 2], json_decode($this->row($model->id, 'doc'), true));
    }

    /**
     * @return array<string, array{string, mixed, mixed}>
     */
    public static function refusedValues(): array
    {
        $values = [
            'too long for the column' => ['short', 'abc', 'much too long'],
            'not in the enum' => ['choice', 'a', 'zzz'],
            'too big for the column' => ['quantity', 1, 5000000000],
            'not a date' => ['moment', '2026-01-01 10:00:00', 'not a date'],
            'not json' => ['doc', '{"a": 1}', '{not json'],
        ];

        if (getenv('DB_CONNECTION') === 'mysql') {
            $values['negative in an unsigned column'] = ['quantity', 1, -1];
        }

        return $values;
    }

    /**
     * @dataProvider refusedValues
     */
    public function test_a_value_the_column_refuses_stays_pending_with_no_vote($column, $from, $to): void
    {
        TypedColumnsModel::$castsUnderTest = [];

        $model = $this->seed($column, $from);
        $before = $this->row($model->id, $column);
        $modification = $this->hold($model, $column, $to);

        try {
            ApproverModel::create(['name' => 'u'])->approve($modification->fresh());
            $this->fail('the column should refuse this');
        } catch (QueryException) {
        }

        $this->assertSame($before, $this->row($model->id, $column));
        $this->assertSame('pending', $modification->fresh()->status);
        $this->assertSame(0, Approval::count());
    }

    public function test_a_refused_direct_save_leaves_no_audit_row(): void
    {
        TypedColumnsModel::$castsUnderTest = [];

        $model = $this->seed('short', 'abc');
        $model->short = 'much too long';

        try {
            $model->save();
            $this->fail('the column should refuse this');
        } catch (QueryException) {
        }

        $this->assertSame(0, Modification::count());
    }

    public function test_long_text_and_emoji_survive_the_diff(): void
    {
        TypedColumnsModel::$castsUnderTest = ['doc' => 'array'];

        $value = ['text' => str_repeat('ñ🙂', 20000)];
        $model = $this->seed('doc', ['text' => 'a']);
        $modification = $this->hold($model, 'doc', $value);

        $this->assertTrue(ApproverModel::create(['name' => 'u'])->approve($modification->fresh()));

        $this->assertSame($value, json_decode($this->row($model->id, 'doc'), true));
    }

    public function test_migrations_can_roll_back_and_run_again(): void
    {
        foreach (['2024_01_03_000000_add_approval_lookup_indexes', '2024_01_02_000000_add_flow_and_modification_items'] as $migration) {
            (require __DIR__ . "/../../database/migrations/$migration.php")->down();
        }

        foreach (['2024_01_02_000000_add_flow_and_modification_items', '2024_01_03_000000_add_approval_lookup_indexes'] as $migration) {
            (require __DIR__ . "/../../database/migrations/$migration.php")->up();
        }

        $this->assertTrue(Capsule::schema()->hasTable('modification_items'));
    }
}
