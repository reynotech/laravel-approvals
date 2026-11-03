<?php

declare(strict_types=1);

namespace ReynoTECH\Approvals\Tests\Stress;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
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
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Facade;
use ReynoTECH\Approvals\Concerns\RequiresApprovalWhenChanges;
use ReynoTECH\Approvals\Models\Modification;
use ReynoTECH\Approvals\Tests\Fixtures\ApproverModel;
use ReynoTECH\Approvals\Tests\TestCase;
use Spatie\SchemalessAttributes\Casts\SchemalessAttributes;

enum U { case A; case B; case C; }
enum S: string { case A = 'a'; case B = 'b'; case C = 'c'; }

final class Money { public function __construct(public int $cents) {} }
class MoneyCast implements CastsAttributes
{
    public function get($m, string $k, $v, array $a) { return $v === null ? null : new Money((int) $v); }
    public function set($m, string $k, $v, array $a) { return $v?->cents; }
}

class Fz extends Model
{
    use RequiresApprovalWhenChanges;

    public static $cast = null;
    public static bool $grouped = false;
    public bool $gate = false;
    protected $table = 'fz';
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return self::$cast ? ['val' => self::$cast] : [];
    }

    protected function requiresApprovalWhenTrait(array $m): bool { return $this->gate; }

    protected function approvableItemGroups(array $d): array
    {
        return self::$grouped ? ['g' => ['val']] : [];
    }
}

class FzAccessor extends Fz
{
    protected function val(): Attribute { return Attribute::make(get: fn ($v) => $v === null ? null : strtoupper($v)); }
}
class FzMutator extends Fz
{
    protected function val(): Attribute { return Attribute::make(set: fn ($v) => $v === null ? null : "[$v]"); }
}
class FzBoth extends Fz
{
    protected function val(): Attribute
    {
        return Attribute::make(get: fn ($v) => $v === null ? null : json_decode($v, true), set: fn ($v) => $v === null ? null : json_encode($v));
    }
}

final class DiffMatrixTest extends TestCase
{
    private array $violations = [];
    private int $cases = 0;

    private function table(string $type): void
    {
        $s = Capsule::schema();
        $s->dropIfExists('fz');
        $s->create('fz', function (Blueprint $t) use ($type) {
            $t->increments('id');
            $t->{$type}('val')->nullable();
            $t->timestamps();
        });
        Capsule::table('modifications')->delete();
        Capsule::table('approvals')->delete();
    }

    private function raw(int $id) { return Capsule::table('fz')->where('id', $id)->value('val'); }

    private function s($v): string { return json_encode($v, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES); }

    private function record(string $inv, string $label, string $detail): void
    {
        $this->violations[$inv][] = "$label :: $detail";
    }

    private function seed(string $class, $v): int
    {
        $m = new $class();
        $m->val = $v;
        $m->save();
        return $m->id;
    }

    private function change(string $class, int $id, $to, bool $gate = false): Model
    {
        $m = $class::find($id);
        $m->gate = $gate;
        $m->val = $to;
        $m->save();
        return $m;
    }

    private function rowOf(?Modification $m): ?array
    {
        return $m?->fresh()?->modifications?->toArray()['val'] ?? null;
    }

    /** direct vs gated+approved vs item+approved, and chain consistency. */
    private function run_(string $label, string $class, $cast, string $type, $from, $to, $third, $user): void
    {
        $this->cases++;
        Fz::$cast = $cast;
        Fz::$grouped = false;
        Model::clearBootedModels();
        $this->table($type);

        try {
            $d = $this->seed($class, $from);
            $g = $this->seed($class, $from);
            $i = $this->seed($class, $from);
            $base = $this->raw($d);
        } catch (\Throwable $e) {
            $this->record('SEED', $label, $e->getMessage());
            return;
        }

        // direct
        try {
            $dm = $this->change($class, $d, $to);
            $rawDirect = $this->raw($d);
            $changed = (string) $rawDirect !== (string) $base || ($rawDirect === null) !== ($base === null);
            $directRow = $changed ? $this->rowOf($dm->getLastModification()) : null;
            if ($changed && ! $directRow) {
                $this->record('I3 cambio directo sin fila de auditoria', $label, 'raw ' . $this->s($base) . ' -> ' . $this->s($rawDirect));
            }
            if (! $changed && $dm->getLastModification()) {
                $this->record('I5 fila de auditoria sin cambio', $label, $this->s($this->rowOf($dm->getLastModification())));
            }
        } catch (\Throwable $e) {
            $this->record('EXC direct', $label, get_class($e) . ': ' . $e->getMessage());
            return;
        }

        // gated
        try {
            $gm = $this->change($class, $g, $to, true);
            $mod = $gm->getLastModification();
            if ($changed) {
                if (! $mod) {
                    if ((string) $this->raw($g) !== (string) $rawDirect) {
                        $this->record('I4 gated sin fila y sin guardar', $label, 'raw ' . $this->s($this->raw($g)));
                    } else {
                        $this->record('I4b gated se guardo sin aprobacion', $label, 'raw ' . $this->s($this->raw($g)));
                    }
                } else {
                    if ((string) $this->raw($g) !== (string) $base) {
                        $this->record('I6 gated escribio antes de aprobar', $label, $this->s($this->raw($g)));
                    }
                    if (! $this->rowOf($mod)) {
                        $this->record('I7 pendiente con diff vacio', $label, 'raw ' . $this->s($base) . ' -> ' . $this->s($rawDirect));
                    }
                    $user->approve($mod);
                    $rawG = $this->raw($g);
                    if ((string) $rawG !== (string) $rawDirect || ($rawG === null) !== ($rawDirect === null)) {
                        $this->record('I1 aprobar != guardar directo', $label, 'direct=' . $this->s($rawDirect) . ' approved=' . $this->s($rawG) . ' row=' . $this->s($this->rowOf($mod)));
                    }
                }
            } elseif ($mod) {
                $this->record('I8 pendiente sin cambio real', $label, $this->s($this->rowOf($mod)));
            }
        } catch (\Throwable $e) {
            $this->record('EXC gated/approve', $label, get_class($e) . ': ' . $e->getMessage());
        }

        // item
        if ($changed) {
            try {
                Fz::$grouped = true;
                $im = $this->change($class, $i, $to, true);
                if ($mod2 = $im->getLastModification()) {
                    $user->approve($mod2);
                    $rawI = $this->raw($i);
                    if ((string) $rawI !== (string) $rawDirect || ($rawI === null) !== ($rawDirect === null)) {
                        $this->record('I2 aprobar item != guardar directo', $label, 'direct=' . $this->s($rawDirect) . ' item=' . $this->s($rawI));
                    }
                }
            } catch (\Throwable $e) {
                $this->record('EXC item', $label, get_class($e) . ': ' . $e->getMessage());
            } finally {
                Fz::$grouped = false;
            }
        }

        // chain: B.ori must equal A.mod; reverting: C.mod must equal A.ori
        if ($changed) {
            try {
                $m2 = $this->change($class, $d, $third);
                $r2 = $this->rowOf($m2->getLastModification());
                if ($directRow && $r2 && $this->s($directRow['mod']) !== $this->s($r2['ori'])) {
                    $this->record('I9 ori siguiente != mod anterior', $label, 'mod=' . $this->s($directRow['mod']) . ' ori=' . $this->s($r2['ori']));
                }
                $m3 = $this->change($class, $d, $from);
                $r3 = $this->rowOf($m3->getLastModification());
                if ($directRow && $r3 && $this->s($directRow['ori']) !== $this->s($r3['mod'])) {
                    $this->record('I10 mod al revertir != ori original', $label, 'ori=' . $this->s($directRow['ori']) . ' mod=' . $this->s($r3['mod']));
                }
            } catch (\Throwable $e) {
                $this->record('EXC chain', $label, get_class($e) . ': ' . $e->getMessage());
            }
        }
    }

    /**
     * Every cast against every transition, comparing a direct save with approving the same change,
     * whole and by item, and checking each diff reads the same before and after. A schemaless attribute
     * lists only the sub-keys that changed, so that last comparison doesn't apply to it.
     */
    public function test_matrix(): void
    {
        set_error_handler(function ($l, $m, $f, $ln) { throw new \ErrorException($m, 0, $l, $f, $ln); }, E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

        $enc = new class {
            public function encrypt($v, $s = true) { return 'ENC:' . base64_encode($s ? serialize($v) : $v); }
            public function encryptString($v) { return $this->encrypt($v, false); }
            public function decrypt($v, $u = true) { $d = base64_decode(substr($v, 4)); return $u ? unserialize($d) : $d; }
            public function decryptString($v) { return $this->decrypt($v, false); }
            public function getKey() { return 'k'; }
            public function getAllKeys() { return ['k']; }
            public function getPreviousKeys() { return []; }
        };
        Model::encryptUsing($enc);
        Facade::getFacadeApplication()->instance('encrypter', $enc);
        Facade::getFacadeApplication()->instance('hash', new class {
            public function isHashed($v) { return str_starts_with((string) $v, 'H$'); }
            public function make($v, $o = []) { return 'H$' . $v; }
            public function verifyConfiguration($v) { return true; }
            public function check($v, $h, $o = []) { return 'H$' . $v === $h; }
        });

        $user = null;

        $scalars = [
            'string' => [null, 'text', ['a', 'b', 'c'], ['', 'a', 'c'], ['a', '', 'c'], ['0', 'a', 'c']],
            'string-num' => [null, 'text', ['1', '1.0', '2']],
            'int' => ['int', 'integer', [1, 2, 3], [0, 1, 2], [1, 0, 2]],
            'integer-col-string' => ['int', 'text', ['1', '2', '3']],
            'float' => ['float', 'float', [1.5, 2.5, 3.0], [1.0, 2.0, 3.0], [0.1, 0.2, 0.3]],
            'double-text' => ['double', 'text', [1.5, 2.5, 3.0]],
            'decimal' => ['decimal:2', 'decimal', ['10.50', '10.60', '11.00'], ['10.5', '10.50', '11']],
            'bool' => ['boolean', 'boolean', [true, false, true], [false, true, false]],
            'date-datecol' => ['date', 'date', ['2026-01-01', '2026-02-02', '2026-03-03']],
            'date-dtcol' => ['date', 'dateTime', ['2026-01-01', '2026-02-02', '2026-03-03']],
            'datetime' => ['datetime', 'dateTime', ['2026-01-01 10:00:00', '2026-02-02 11:30:00', '2026-03-03 12:00:00']],
            'immutable_date' => ['immutable_date', 'date', ['2026-01-01', '2026-02-02', '2026-03-03']],
            'immutable_datetime' => ['immutable_datetime', 'dateTime', ['2026-01-01 10:00:00', '2026-02-02 11:30:00', '2026-03-03 12:00:00']],
            'datetime-fmt' => ['datetime:d/m/Y', 'dateTime', ['2026-01-01 10:00:00', '2026-02-02 11:30:00', '2026-03-03 12:00:00']],
            'date-fmt' => ['date:d/m/Y', 'date', ['2026-01-01', '2026-02-02', '2026-03-03']],
            'datetime-tz' => ['datetime', 'dateTime', [new \Carbon\Carbon('2026-01-01 10:00:00', 'America/Mexico_City'), new \Carbon\Carbon('2026-02-02 11:30:00', 'Asia/Tokyo'), new \Carbon\Carbon('2026-03-03 12:00:00', 'UTC')]],
            'timestamp-int' => ['timestamp', 'integer', [1700000000, 1700000100, 1700000200]],
            'timestamp-dt' => ['timestamp', 'dateTime', ['2026-01-01 10:00:00', '2026-02-02 11:30:00', '2026-03-03 12:00:00']],
            'enum-backed' => [S::class, 'string', [S::A, S::B, S::C]],
            'enum-unit' => [U::class, 'string', [U::A, U::B, U::C]],
            'stringable' => [AsStringable::class, 'text', ['a', 'b', 'c']],
            'encrypted' => ['encrypted', 'text', ['a', 'b', 'c']],
            'money' => [MoneyCast::class, 'integer', [new Money(1), new Money(2), new Money(3)]],
            'hashed' => ['hashed', 'text', ['p1', 'p2', 'p3']],
        ];
        if (class_exists(AsHtmlString::class)) {
            $scalars['html'] = [AsHtmlString::class, 'text', ['<b>a</b>', '<b>b</b>', '<b>c</b>']];
        }

        $collections = [
            'array' => 'array', 'json' => 'json', 'collection' => 'collection',
            'AsCollection' => AsCollection::class, 'AsArrayObject' => AsArrayObject::class,
            'encrypted:array' => 'encrypted:array', 'encrypted:collection' => 'encrypted:collection',
            'AsEncCollection' => AsEncryptedCollection::class, 'AsEncArrayObject' => AsEncryptedArrayObject::class,
        ];
        if (class_exists(AsFluent::class)) {
            $collections['AsFluent'] = AsFluent::class;
        }

        $shapes = [
            'list-add' => [['a', 'b'], ['a', 'b', 'c'], ['z']],
            'list-remove' => [['a', 'b', 'c'], ['a', 'b'], ['z']],
            'list-reorder' => [['a', 'b'], ['b', 'a'], ['z']],
            'map-edit' => [['x' => 1, 'y' => 2], ['x' => 1, 'y' => 3], ['z' => 1]],
            'map-remove' => [['x' => 1, 'y' => 2], ['x' => 1], ['z' => 1]],
            'map-reorder' => [['x' => 1, 'y' => 2], ['y' => 2, 'x' => 1], ['z' => 1]],
            'nested-edit' => [['n' => ['x' => 1, 'y' => 2]], ['n' => ['x' => 1, 'y' => 3]], ['z' => 1]],
            'nested-remove' => [['n' => ['x' => 1, 'y' => 2]], ['n' => ['x' => 1]], ['z' => 1]],
            'nested-reorder' => [['n' => ['x' => 1, 'y' => 2]], ['n' => ['y' => 2, 'x' => 1]], ['z' => 1]],
            'nested-to-scalar' => [['n' => ['x' => 1]], ['n' => 'v'], ['z' => 1]],
            'scalar-to-nested' => [['n' => 'v'], ['n' => ['x' => 1]], ['z' => 1]],
            'to-empty' => [['a'], [], ['z']],
            'from-empty' => [[], ['a'], ['z']],
            'null-value-in' => [['x' => null], ['x' => 1], ['z' => 1]],
            'null-value-out' => [['x' => 1], ['x' => null], ['z' => 1]],
            'null-vs-missing' => [['x' => null, 'y' => 1], ['y' => 1], ['z' => 1]],
            'int-vs-string' => [['x' => 1], ['x' => '1'], ['z' => 1]],
            'bool-vs-int' => [['x' => true], ['x' => 1], ['z' => 1]],
            'float-small' => [['x' => 1e-20], ['x' => 2e-20], ['z' => 1]],
            'float-money' => [['x' => 0.1], ['x' => 0.1000000000000001], ['z' => 1]],
            'float-int' => [['x' => 1], ['x' => 1.5], ['z' => 1]],
            'sparse-keys' => [[5 => 'a', 7 => 'b'], [5 => 'a', 8 => 'b'], ['z']],
            'list-to-map' => [['a', 'b'], ['x' => 'a'], ['z']],
            'unicode' => [['x' => 'año'], ['x' => 'ñandú €'], ['z' => 1]],
            'empty-nested' => [['x' => []], ['x' => ['a']], ['z' => 1]],
        ];

        $user = ApproverModel::create(['name' => 'u']);

        foreach ($scalars as $name => $spec) {
            [$cast, $type] = [$spec[0], $spec[1]];
            foreach (array_slice($spec, 2) as $n => [$a, $b, $c]) {
                $this->run_("$name#$n a->b", Fz::class, $cast, $type, $a, $b, $c, $user);
                $this->run_("$name#$n null->b", Fz::class, $cast, $type, null, $b, $c, $user);
                $this->run_("$name#$n a->null", Fz::class, $cast, $type, $a, null, $c, $user);
                $this->run_("$name#$n a->a", Fz::class, $cast, $type, $a, $a, $c, $user);
            }
        }

        foreach ($collections as $name => $cast) {
            foreach ($shapes as $shape => [$a, $b, $c]) {
                $this->run_("$name/$shape", Fz::class, $cast, 'text', $a, $b, $c, $user);
            }
            $this->run_("$name/null->v", Fz::class, $cast, 'text', null, ['a'], ['z'], $user);
            $this->run_("$name/v->null", Fz::class, $cast, 'text', ['a'], null, ['z'], $user);
            $this->run_("$name/[]->null", Fz::class, $cast, 'text', [], null, ['z'], $user);
            $this->run_("$name/null->[]", Fz::class, $cast, 'text', null, [], ['z'], $user);
            $this->run_("$name/same", Fz::class, $cast, 'text', ['a'], ['a'], ['z'], $user);
        }

        foreach (['array', 'json', 'collection', AsCollection::class] as $cast) {
            $this->run_("$cast/scalar-json->arr", Fz::class, $cast, 'text', 'str', ['a'], ['z'], $user);
            $this->run_("$cast/arr->scalar-json", Fz::class, $cast, 'text', ['a'], 'str', ['z'], $user);
        }

        // object cast
        $o = fn ($v) => (object) $v;
        foreach ([[['a' => 1], ['a' => 2], ['a' => 3]], [['a' => 1], [], ['a' => 3]], [[], ['a' => 1], ['a' => 3]]] as $n => [$a, $b, $c]) {
            $this->run_("object#$n", Fz::class, 'object', 'text', $o($a), $o($b), $o($c), $user);
        }
        $this->run_('object/null->v', Fz::class, 'object', 'text', null, $o(['a' => 1]), $o(['a' => 3]), $user);
        $this->run_('object/v->null', Fz::class, 'object', 'text', $o(['a' => 1]), null, $o(['a' => 3]), $user);
        $this->run_('array/empty-object-inside', Fz::class, 'array', 'text', ['x' => 1], ['x' => new \stdClass()], ['z' => 1], $user);

        // enum collections
        $this->run_('AsEnumCollection-backed', Fz::class, AsEnumCollection::of(S::class), 'text', [S::A], [S::A, S::B], [S::C], $user);
        $this->run_('AsEnumCollection-backed/remove', Fz::class, AsEnumCollection::of(S::class), 'text', [S::A, S::B], [S::A], [S::C], $user);
        $this->run_('AsEnumCollection-unit', Fz::class, AsEnumCollection::of(U::class), 'text', [U::A], [U::A, U::B], [U::C], $user);
        $this->run_('AsEnumArrayObject-backed', Fz::class, AsEnumArrayObject::of(S::class), 'text', [S::A], [S::A, S::B], [S::C], $user);
        $this->run_('AsEnumArrayObject-unit', Fz::class, AsEnumArrayObject::of(U::class), 'text', [U::A], [U::A, U::B], [U::C], $user);

        // schemaless (partial by design)
        foreach ([[['a' => 1], ['a' => 2], ['a' => 3]], [['a' => 1], ['a' => 1, 'b' => 2], ['a' => 3]], [['a' => ['x' => 1, 'y' => 2]], ['a' => ['x' => 9, 'y' => 2]], ['a' => 3]]] as $n => [$a, $b, $c]) {
            $this->run_("schemaless#$n", Fz::class, SchemalessAttributes::class, 'text', $a, $b, $c, $user);
        }

        // accessor / mutator
        foreach ([[FzAccessor::class, 'accessor', ['a', 'b', 'c']], [FzMutator::class, 'mutator', ['a', 'b', 'c']], [FzBoth::class, 'both-array', [['a'], ['b'], ['c']]]] as [$cls, $name, [$a, $b, $c]]) {
            $this->run_("$name a->b", $cls, null, 'text', $a, $b, $c, $user);
            $this->run_("$name null->b", $cls, null, 'text', null, $b, $c, $user);
            $this->run_("$name a->null", $cls, null, 'text', $a, null, $c, $user);
            $this->run_("$name a->a", $cls, null, 'text', $a, $a, $c, $user);
        }

        restore_error_handler();
        Model::encryptUsing(null);

        $unexpected = [];
        ksort($this->violations);

        foreach ($this->violations as $invariant => $rows) {
            foreach ($rows as $row) {
                $partialByDesign = str_starts_with($row, 'schemaless') && preg_match('/^I(9|10) /', $invariant);

                if (! $partialByDesign) {
                    $unexpected[] = "$invariant: $row";
                }
            }
        }

        $this->assertSame([], $unexpected, implode("\n", $unexpected));
        $this->assertGreaterThan(400, $this->cases);
    }
}
