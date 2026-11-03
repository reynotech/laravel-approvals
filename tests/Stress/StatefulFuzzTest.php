<?php

declare(strict_types=1);

namespace ReynoTECH\Approvals\Tests\Stress;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use ReynoTECH\Approvals\Concerns\RequiresApprovalWhenChanges;
use ReynoTECH\Approvals\Contracts\GatesCreation;
use ReynoTECH\Approvals\Models\Approval;
use ReynoTECH\Approvals\Models\Disapproval;
use ReynoTECH\Approvals\Models\Modification;
use ReynoTECH\Approvals\Tests\Fixtures\ApproverModel;
use ReynoTECH\Approvals\Tests\TestCase;

class Base extends Model
{
    use RequiresApprovalWhenChanges;

    public const REQ = 1;
    public const GROUPS = [];
    public bool $gate = false;
    protected $table = 'sm';
    protected $guarded = ['id'];
    protected $casts = ['payload' => 'array', 'stamp' => 'date', 'flag' => 'boolean'];

    protected function requiresApprovalWhenTrait(array $m): bool { return $this->gate; }
    protected function approversRequired(): int { return static::REQ; }
    protected function disapproversRequired(): int { return static::REQ; }
    protected function approvableItemGroups(array $d): array { return static::GROUPS; }
}
class One extends Base {}
class Two extends Base { public const REQ = 2; }
class Grouped extends Base { public const GROUPS = ['g1' => ['name', 'status'], 'g2' => ['payload']]; }
class GroupedTwo extends Grouped { public const REQ = 2; }
class Deferred extends Base implements GatesCreation
{
    public function requiresApprovalOnCreate(array $a): bool { return ($a['name'] ?? null) === 'g'; }
    public function approvalCreationMode(): string { return GatesCreation::DEFERRED; }
}
class Draft extends Base implements GatesCreation
{
    public const REQ = 2;
    public function requiresApprovalOnCreate(array $a): bool { return ($a['name'] ?? null) === 'g'; }
    public function approvalCreationMode(): string { return GatesCreation::DRAFT; }
}

final class FuzzFailure extends \RuntimeException {}

final class StatefulFuzzTest extends TestCase
{
    private const FIELDS = ['name', 'status', 'payload', 'stamp', 'flag'];
    private array $log = [];
    private array $rows = [];       // id => [class, attrs, hidden]
    private array $decisions = [];  // modification id => [...]
    private array $users = [];

    private function values(string $f): array
    {
        return match ($f) {
            'name' => ['a', 'b', 'c'],
            'status' => ['x', 'y', null],
            'payload' => [['a'], ['a', 'b'], ['m' => 1], [], null],
            'stamp' => ['2026-01-01', '2026-02-02', null],
            'flag' => [true, false],
        };
    }

    private function pick(array $a) { return $a[array_rand($a)]; }

    private function fail_(string $m): void { throw new FuzzFailure($m); }

    private function read(string $class, int $id): ?array
    {
        $m = $class::withoutGlobalScopes()->find($id);
        return $m ? $this->attrs($m) : null;
    }

    private function attrs(Model $m): array
    {
        return [
            'name' => $m->name, 'status' => $m->status, 'payload' => $m->payload,
            'stamp' => $m->stamp?->format('Y-m-d'), 'flag' => $m->flag === null ? null : (bool) $m->flag,
        ];
    }

    private function createRow(string $class, array $attrs): void
    {
        $m = new $class();
        foreach ($attrs as $k => $v) { $m->{$k} = $v; }
        $m->save();
        $this->log[] = "create $class " . json_encode($attrs);

        if ($attrs['name'] === 'g' && $class === Deferred::class) {
            $mod = $m->getLastModification();
            if ($m->exists || ! $mod) { $this->fail_('deferred create should hold with a request'); }
            $key = $mod->id;
            if (! isset($this->decisions[$key])) {
                $this->decisions[$key] = $this->decision($mod, null, $class, $attrs, 'creation', true);
            }
            return;
        }
        if (! $m->exists) { $this->fail_('create should have inserted'); }
        $full = array_merge(array_fill_keys(self::FIELDS, null), $attrs);
        $hidden = $attrs['name'] === 'g' && $class === Draft::class;
        $this->rows[$m->id] = ['class' => $class, 'attrs' => $full, 'hidden' => $hidden, 'instance' => null];
        if ($hidden) {
            $mod = $m->getLastModification();
            if (! $mod) { $this->fail_('draft should have a request'); }
            $this->decisions[$mod->id] = $this->decision($mod, $m->id, $class, $attrs, 'creation', false);
        }
    }

    private function decision(Modification $mod, ?int $rowId, string $class, array $proposed, string $kind, bool $deferred = false): array
    {
        return ['mod' => $mod, 'rowId' => $rowId, 'class' => $class, 'proposed' => $proposed, 'kind' => $kind,
            'deferred' => $deferred, 'units' => [], 'status' => 'pending', 'approvals' => [], 'disapprovals' => [], 'req' => $class::REQ];
    }

    private function change(int $id, bool $gate, bool $reuse): void
    {
        $row = $this->rows[$id];
        $class = $row['class'];
        // Reuse only an instance still in step with its row: a stale one diffs against its old copy, as Eloquent does.
        $inst = $reuse && $row['instance'] && $this->attrs($row['instance']) === $row['attrs']
            ? $row['instance']
            : $class::withoutGlobalScopes()->find($id);
        if (! $inst) { return; }
        $this->rows[$id]['instance'] = $inst;
        $inst->gate = $gate;

        $fields = (array) array_rand(array_flip(self::FIELDS), mt_rand(1, 3));
        $wanted = [];
        foreach ($fields as $f) { $wanted[$f] = $this->pick($this->values($f)); $inst->{$f} = $wanted[$f]; }

        $before = $row['attrs'];
        $changed = [];
        foreach ($wanted as $f => $v) { if ($before[$f] !== $v) { $changed[$f] = $v; } }

        $last = $inst->getLastModification();
        $saved = $inst->save();
        $this->log[] = ($gate ? 'gated' : 'direct') . ($reuse ? '(reuse)' : '') . " #$id " . json_encode($wanted) . " changed=" . json_encode($changed);

        $now = $inst->getLastModification();

        if (! $gate) {
            if (! $saved) { $this->fail_('direct save returned false'); }
            $this->rows[$id]['attrs'] = array_merge($before, $changed);
            if ($changed) {
                if (! $now || $now === $last) { $this->fail_('direct change left no audit row'); }
                $diff = $now->fresh()->modifications->toArray();
                foreach ($changed as $f => $v) {
                    if (! array_key_exists($f, $diff)) { $this->fail_("audit lacks $f"); }
                    if ($diff[$f]['ori'] !== $before[$f]) { $this->fail_("audit ori $f: " . json_encode($diff[$f]['ori']) . ' != ' . json_encode($before[$f])); }
                    if ($diff[$f]['mod'] !== $v) { $this->fail_("audit mod $f: " . json_encode($diff[$f]['mod']) . ' != ' . json_encode($v)); }
                }
                if (array_diff(array_keys($diff), array_keys($changed))) { $this->fail_('audit has extra keys: ' . json_encode($diff)); }
                if ($now->fresh()->status !== 'applied') { $this->fail_('audit not applied'); }
                $this->decisions[$now->id] = ['direct' => true, 'mod' => $now];
            } elseif ($now !== $last && $now) {
                $this->fail_('no change but audit row ' . json_encode($now->modifications));
            }
            return;
        }

        if (! $changed) {
            if (! $saved) { $this->fail_('gated save with nothing to decide returned false'); }
            if ($now !== $last) { $this->fail_('gated no-op created a row'); }
            return;
        }

        if ($saved) { $this->fail_('gated change saved'); }
        if (! $now) { $this->fail_('gated change left no row'); }
        $diff = $now->fresh()->modifications->toArray();
        if (array_keys($diff) != array_keys($changed) && array_diff(array_keys($diff), array_keys($changed))) {
            $this->fail_('gated diff carries extra keys: ' . json_encode(array_keys($diff)) . ' vs ' . json_encode(array_keys($changed)));
        }
        foreach ($changed as $f => $v) {
            if (! array_key_exists($f, $diff)) { $this->fail_("gated diff lacks $f"); }
            if ($diff[$f]['mod'] !== $v) { $this->fail_("gated mod $f"); }
            if ($diff[$f]['ori'] !== $before[$f]) { $this->fail_("gated ori $f"); }
        }
        if (isset($this->decisions[$now->id])) { return; } // the same pending request

        $d = $this->decision($now, $id, $class, $changed, 'change');
        $groups = $class::GROUPS;
        $units = [];
        foreach ($groups as $g => $fs) {
            $hit = array_values(array_intersect($fs, array_keys($changed)));
            if ($hit) { $units[$g] = ['fields' => $hit, 'approvals' => [], 'disapprovals' => [], 'status' => 'pending']; }
        }
        if ($units) {
            $covered = array_merge(...array_values(array_column($units, 'fields')));
            $left = array_values(array_diff(array_keys($changed), $covered));
            if ($left) { $units['_ungrouped'] = ['fields' => $left, 'approvals' => [], 'disapprovals' => [], 'status' => 'pending']; }
        }
        $d['units'] = $units;
        $this->decisions[$now->id] = $d;
    }

    private function vote(int $modId, int $uid, bool $approve): void
    {
        $user = $this->users[$uid];
        $d = &$this->decisions[$modId];
        $reason = $this->pick([null, 'r1', 'r2']);
        $result = $approve ? $user->approve($d['mod'], $reason) : $user->disapprove($d['mod'], $reason);
        $this->log[] = ($approve ? 'approve' : 'disapprove') . " mod#$modId by u$uid reason=" . json_encode($reason) . ' -> ' . json_encode($result);

        if (isset($d['direct']) || $d['status'] !== 'pending') {
            if ($result !== false) { $this->fail_('vote on a decided row returned true'); }
            return;
        }

        $missing = $d['kind'] !== 'creation' || ! $d['deferred'] ? ($d['rowId'] !== null && ! isset($this->rows[$d['rowId']])) : false;
        if ($missing && $approve) {
            if ($result !== false) { $this->fail_('approving a deleted model returned true'); }
            return;
        }
        if ($result !== true) { $this->fail_('valid vote returned false'); }

        if ($d['units']) {
            foreach (array_keys($d['units']) as $k) {
                if ($d['units'][$k]['status'] === 'pending') { $this->unitVote($modId, $k, $uid, $approve); }
            }
            return;
        }

        $this->tally($d, $uid, $approve);
        $reached = $approve ? count($d['approvals']) >= $d['req'] : count($d['disapprovals']) >= $d['req'];
        if (! $reached) { return; }

        $d['status'] = $approve ? 'approved' : 'rejected';
        if ($d['kind'] === 'creation') {
            if ($d['deferred']) {
                if ($approve) {
                    $newId = (int) $d['mod']->fresh()->modifiable_id;
                    if (! $newId) { $this->fail_('deferred approved without a linked id'); }
                    $this->rows[$newId] = ['class' => $d['class'], 'attrs' => array_merge(array_fill_keys(self::FIELDS, null), $d['proposed']), 'hidden' => false, 'instance' => null];
                }
            } elseif (isset($this->rows[$d['rowId']])) {
                if ($approve) { $this->rows[$d['rowId']]['hidden'] = false; } else { unset($this->rows[$d['rowId']]); }
            }
        } elseif ($approve) {
            $this->rows[$d['rowId']]['attrs'] = array_merge($this->rows[$d['rowId']]['attrs'], $d['proposed']);
        }
    }

    private function tally(array &$holder, int $uid, bool $approve): void
    {
        if ($approve) {
            unset($holder['disapprovals'][$uid]);
            $holder['approvals'][$uid] = true;
        } else {
            unset($holder['approvals'][$uid]);
            $holder['disapprovals'][$uid] = true;
        }
    }

    private function unitVote(int $modId, string $k, int $uid, bool $approve): void
    {
        $d = &$this->decisions[$modId];
        $u = &$d['units'][$k];
        $this->tally($u, $uid, $approve);
        $reached = $approve ? count($u['approvals']) >= $d['req'] : count($u['disapprovals']) >= $d['req'];
        if ($reached) {
            $u['status'] = $approve ? 'applied' : 'rejected';
            if ($approve) {
                foreach ($u['fields'] as $f) { $this->rows[$d['rowId']]['attrs'][$f] = $d['proposed'][$f]; }
            }
        }
        $st = array_column($d['units'], 'status');
        if (in_array('pending', $st, true)) { $d['status'] = 'pending'; }
        elseif (! array_diff($st, ['applied'])) { $d['status'] = 'approved'; }
        elseif (! array_diff($st, ['rejected'])) { $d['status'] = 'rejected'; }
        else { $d['status'] = 'partially_resolved'; }
    }

    private function itemVote(int $modId, string $k, int $uid, bool $approve): void
    {
        $d = &$this->decisions[$modId];
        $item = $d['mod']->items()->where('key', $k)->first();
        $user = $this->users[$uid];
        $result = $approve ? $user->approveItem($item) : $user->disapproveItem($item);
        $this->log[] = ($approve ? 'approveItem' : 'disapproveItem') . " mod#$modId/$k by u$uid -> " . json_encode($result);
        $u = $d['units'][$k];
        if ($u['status'] !== 'pending') {
            if ($result !== false) { $this->fail_('vote on a decided item returned true'); }
            return;
        }
        $missing = ! isset($this->rows[$d['rowId']]);
        if ($missing && $approve) {
            if ($result !== false) { $this->fail_('item approve on deleted model returned true'); }
            return;
        }
        if ($result !== true) { $this->fail_('valid item vote returned false'); }
        $this->unitVote($modId, $k, $uid, $approve);
    }

    private function check(): void
    {
        foreach ($this->rows as $id => $row) {
            $actual = $this->read($row['class'], $id);
            if ($actual === null) { $this->fail_("row #$id missing"); }
            if ($actual !== $row['attrs']) {
                $this->fail_("row #$id\n  expected " . json_encode($row['attrs']) . "\n  actual   " . json_encode($actual));
            }
            $visible = $row['class']::find($id) !== null;
            if ($visible === $row['hidden']) { $this->fail_("row #$id visibility: visible=" . json_encode($visible) . ' hidden=' . json_encode($row['hidden'])); }
        }
        $total = Capsule::table('sm')->count();
        if ($total !== count($this->rows)) { $this->fail_("row count $total != " . count($this->rows)); }

        $votesApproval = 0;
        $votesDisapproval = 0;
        foreach ($this->decisions as $id => $d) {
            if (isset($d['direct'])) { continue; }
            $mod = $d['mod']->fresh();
            if ($mod->status !== $d['status']) { $this->fail_("mod#$id status {$mod->status} != {$d['status']}"); }
            if ($mod->active !== ($d['status'] === 'pending')) { $this->fail_("mod#$id active flag wrong"); }
            if ($mod->is_modification !== true) { $this->fail_("mod#$id is_modification"); }
            foreach ($d['units'] as $k => $u) {
                $item = $mod->items()->where('key', $k)->first();
                if (! $item) { $this->fail_("mod#$id missing item $k"); }
                if ($item->status !== $u['status']) { $this->fail_("mod#$id/$k item status {$item->status} != {$u['status']}"); }
                $a = Approval::where('modification_item_id', $item->id)->count();
                $x = Disapproval::where('modification_item_id', $item->id)->count();
                if ($a !== count($u['approvals']) || $x !== count($u['disapprovals'])) {
                    $this->fail_("mod#$id/$k votes a=$a x=$x expected " . count($u['approvals']) . '/' . count($u['disapprovals']));
                }
            }
            if ($mod->items()->count() !== count($d['units'])) { $this->fail_("mod#$id item count"); }
            if (! $d['units']) {
                $a = $mod->approvals()->count();
                $x = $mod->disapprovals()->count();
                if ($a !== count($d['approvals']) || $x !== count($d['disapprovals'])) {
                    $this->fail_("mod#$id votes a=$a x=$x expected " . count($d['approvals']) . '/' . count($d['disapprovals']));
                }
            }
        }
    }

    /**
     * Random operations on several models, checked after each step against a model of what should have
     * happened. A failure prints its seed; each seed replays the same sequence.
     */
    public function test_random_sequences(): void
    {
        set_error_handler(function ($l, $m, $f, $ln) { throw new \ErrorException($m, 0, $l, $f, $ln); }, E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

        $schema = Capsule::schema();
        $schema->dropIfExists('sm');
        $schema->create('sm', function (Blueprint $t) {
            $t->increments('id');
            foreach (['name', 'status', 'payload', 'stamp'] as $c) { $t->text($c)->nullable(); }
            $t->boolean('flag')->nullable();
            $t->boolean('approval_pending')->default(false);
            $t->timestamps();
        });
        for ($i = 0; $i < 3; $i++) { $this->users[$i] = ApproverModel::create(['name' => "u$i"]); }

        $seeds = (int) (getenv('FUZZ_SEEDS') ?: 100);
        $steps = 60;
        $failures = [];
        $opsRun = 0;

        for ($seed = 1; $seed <= $seeds; $seed++) {
            mt_srand($seed);
            $this->log = [];
            $this->rows = [];
            $this->decisions = [];
            foreach (['sm', 'approvals', 'disapprovals', 'modification_items', 'modifications'] as $t) { Capsule::table($t)->delete(); }

            $classes = [One::class, Two::class, Grouped::class, GroupedTwo::class, Deferred::class, Draft::class];

            try {
                foreach ($classes as $c) {
                    $this->createRow($c, ['name' => 'a', 'status' => 'x', 'payload' => ['a'], 'stamp' => '2026-01-01', 'flag' => true]);
                }
                $this->check();

                for ($s = 0; $s < $steps; $s++) {
                    $opsRun++;
                    $op = mt_rand(1, 100);
                    $ids = array_keys($this->rows);
                    $mods = array_keys($this->decisions);

                    if ($op <= 25 && $ids) {
                        $this->change($this->pick($ids), true, mt_rand(0, 1) === 1);
                    } elseif ($op <= 40 && $ids) {
                        $this->change($this->pick($ids), false, mt_rand(0, 1) === 1);
                    } elseif ($op <= 75 && $mods) {
                        $this->vote($this->pick($mods), mt_rand(0, 2), mt_rand(0, 2) > 0);
                    } elseif ($op <= 88 && $mods) {
                        $withItems = array_values(array_filter($mods, fn ($m) => ! empty($this->decisions[$m]['units'])));
                        if ($withItems) {
                            $m = $this->pick($withItems);
                            $this->itemVote($m, $this->pick(array_keys($this->decisions[$m]['units'])), mt_rand(0, 2), mt_rand(0, 2) > 0);
                        }
                    } elseif ($op <= 95) {
                        $c = $this->pick([Deferred::class, Draft::class, One::class]);
                        $this->createRow($c, ['name' => $this->pick(['g', 'a']), 'status' => $this->pick(['x', 'y']), 'payload' => $this->pick([['a'], null]), 'stamp' => '2026-01-01', 'flag' => $this->pick([true, false])]);
                    } elseif ($ids) {
                        $id = $this->pick($ids);
                        $this->log[] = "delete #$id";
                        Capsule::table('sm')->where('id', $id)->delete();
                        unset($this->rows[$id]);
                    }
                    $this->check();
                }
            } catch (\Throwable $e) {
                $failures[] = "SEED $seed: " . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n  LOG:\n    " . implode("\n    ", array_slice($this->log, -10));
                if (count($failures) >= 6) { break; }
            }
        }

        restore_error_handler();
        $this->assertSame([], $failures, implode("\n\n", $failures));
        $this->assertGreaterThan(0, $opsRun);
    }
}
