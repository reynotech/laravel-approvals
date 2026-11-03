<?php

declare(strict_types=1);

namespace ReynoTECH\Approvals\Tests\Stress;

use Illuminate\Database\Capsule\Manager as Capsule;
use ReynoTECH\Approvals\Models\Approval;
use ReynoTECH\Approvals\Models\Disapproval;
use ReynoTECH\Approvals\Models\Modification;
use ReynoTECH\Approvals\Tests\Fixtures\ApproverModel;
use ReynoTECH\Approvals\Tests\TestCase;

/**
 * Separate processes acting on the same row at the same instant, which only a real server can
 * serialize: SQLite ignores row locks, so this suite needs MySQL or Postgres.
 */
final class ConcurrencyTest extends TestCase
{
    private const WORKERS = 8;

    private function rounds(): int
    {
        return (int) (getenv('CONCURRENCY_ROUNDS') ?: 15);
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::usesServer()) {
            $this->markTestSkipped('Row locks need a real server: run it with bin/bench concurrency.');
        }
    }

    protected function tearDown(): void
    {
        putenv('CONCURRENCY_REQUIRED');

        parent::tearDown();
    }

    /** @return list<array{ok: mixed, error: ?string}> */
    private function together(array $jobs): array
    {
        $at = microtime(true) + 0.6;
        $running = [];

        foreach ($jobs as $job) {
            $command = [PHP_BINARY, __DIR__ . '/concurrency-worker.php', json_encode($job + ['at' => $at])];
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $running[] = [$process, $pipes];
        }

        $results = [];

        foreach ($running as [$process, $pipes]) {
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);

            $line = json_decode(trim($out), true);
            $results[] = is_array($line) ? $line : ['ok' => null, 'error' => 'worker died: ' . trim($out . $err)];
        }

        return $results;
    }

    private function assertNoErrors(array $results, string $context): void
    {
        $errors = array_values(array_filter(array_column($results, 'error')));

        $this->assertSame([], $errors, "$context: a concurrent vote must not surface as an error");
    }

    /** @return list<int> */
    private function users(int $count): array
    {
        return array_map(fn ($i) => ApproverModel::create(['name' => "u$i"])->id, range(1, $count));
    }

    private function held(string $class, array $changes): Modification
    {
        $model = $class::create(['name' => 'n', 'status' => 'draft', 'notes' => 'a']);
        $model = $class::find($model->id);
        $model->gate = true;
        $model->forceFill($changes)->save();

        return $model->getLastModification();
    }

    public function test_many_approvers_fill_the_quota_exactly_once(): void
    {
        putenv('CONCURRENCY_REQUIRED=2');
        $users = $this->users(self::WORKERS);

        for ($round = 0; $round < $this->rounds(); $round++) {
            $modification = $this->held(ConcurrentModel::class, ['status' => "round-$round"]);

            $results = $this->together(array_map(
                fn ($user) => ['action' => 'approve', 'user' => $user, 'modification' => $modification->id],
                $users
            ));

            $this->assertNoErrors($results, "round $round");
            $this->assertSame('approved', $modification->fresh()->status);
            $this->assertSame(2, Approval::where('modification_id', $modification->id)->count(), 'votes beyond the quota');
            $this->assertCount(2, array_filter(array_column($results, 'ok')), 'exactly the votes taken report success');
            $this->assertSame("round-$round", Capsule::table('regression_models')->where('id', $modification->modifiable_id)->value('status'));
        }
    }

    public function test_one_approver_voting_many_times_holds_one_vote(): void
    {
        putenv('CONCURRENCY_REQUIRED=2');
        [$user] = $this->users(1);

        for ($round = 0; $round < $this->rounds(); $round++) {
            $modification = $this->held(ConcurrentModel::class, ['status' => "round-$round"]);

            $results = $this->together(array_map(
                fn ($i) => ['action' => 'approve', 'user' => $user, 'modification' => $modification->id, 'reason' => "r$i"],
                range(1, self::WORKERS)
            ));

            $this->assertNoErrors($results, "round $round");
            $this->assertSame(1, Approval::where('modification_id', $modification->id)->count());
            $this->assertSame('pending', $modification->fresh()->status);
        }
    }

    public function test_approving_and_rejecting_at_once_decides_one_way(): void
    {
        $users = $this->users(self::WORKERS);

        for ($round = 0; $round < $this->rounds(); $round++) {
            $modification = $this->held(ConcurrentModel::class, ['status' => "round-$round"]);

            $results = $this->together(array_map(
                fn ($i, $user) => ['action' => $i % 2 ? 'approve' : 'disapprove', 'user' => $user, 'modification' => $modification->id],
                array_keys($users),
                $users
            ));

            $this->assertNoErrors($results, "round $round");

            $fresh = $modification->fresh();
            $column = Capsule::table('regression_models')->where('id', $fresh->modifiable_id)->value('status');

            $this->assertContains($fresh->status, ['approved', 'rejected']);
            $this->assertSame($fresh->status === 'approved' ? "round-$round" : 'draft', $column, 'the row and its status disagree');
            $this->assertCount(1, array_filter(array_column($results, 'ok')), 'only one decision is taken');
        }
    }

    public function test_items_decided_at_once_leave_a_correct_status(): void
    {
        $users = $this->users(3);

        for ($round = 0; $round < $this->rounds(); $round++) {
            $modification = $this->held(ConcurrentGroupedModel::class, ['status' => "s$round", 'notes' => "n$round", 'name' => "m$round"]);
            $items = $modification->items()->pluck('id')->all();
            $this->assertCount(3, $items);

            $results = $this->together(array_map(
                fn ($item, $user) => ['action' => 'approveItem', 'user' => $user, 'item' => $item],
                $items,
                $users
            ));

            $this->assertNoErrors($results, "round $round");
            $this->assertSame('approved', $modification->fresh()->status, 'a status recalculated from stale items');

            $row = Capsule::table('regression_models')->where('id', $modification->modifiable_id)->first();
            $this->assertSame(["s$round", "n$round", "m$round"], [$row->status, $row->notes, $row->name], 'an item overwrote another');
        }
    }

    public function test_forcing_and_voting_at_once_apply_once(): void
    {
        $users = $this->users(4);

        for ($round = 0; $round < $this->rounds(); $round++) {
            $modification = $this->held(ConcurrentModel::class, ['status' => "round-$round"]);

            $jobs = array_map(fn ($user) => ['action' => 'disapprove', 'user' => $user, 'modification' => $modification->id], $users);
            $jobs[] = ['action' => 'force', 'modification' => $modification->id];

            $results = $this->together($jobs);

            $this->assertNoErrors($results, "round $round");

            $fresh = $modification->fresh();
            $column = Capsule::table('regression_models')->where('id', $fresh->modifiable_id)->value('status');
            $this->assertSame($fresh->status === 'approved' ? "round-$round" : 'draft', $column, 'the row and its status disagree');
            $this->assertSame(
                $fresh->status === 'approved',
                Disapproval::where('modification_id', $modification->id)->count() === 0,
                'a rejection and a forced approval both took effect'
            );
        }
    }

    public function test_the_same_change_requested_at_once_leaves_one_pending_row(): void
    {
        for ($round = 0; $round < $this->rounds(); $round++) {
            $model = ConcurrentModel::create(['name' => 'n', 'status' => 'draft']);

            $results = $this->together(array_map(
                fn () => ['action' => 'hold', 'model' => $model->id, 'changes' => ['status' => 'same']],
                range(1, self::WORKERS)
            ));

            $this->assertNoErrors($results, "round $round");
            $this->assertSame(1, Modification::where('modifiable_id', $model->id)->where('status', 'pending')->count(), 'duplicate pending rows');
        }
    }
}
