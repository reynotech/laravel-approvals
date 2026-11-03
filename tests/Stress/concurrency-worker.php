<?php

declare(strict_types=1);

/**
 * One separate process in ConcurrencyTest: its own connection, voting at the same instant as the others.
 * Usage: php concurrency-worker.php '<json job>'. Prints one JSON line with what happened.
 */

use Illuminate\Database\Capsule\Manager as Capsule;
use ReynoTECH\Approvals\Models\Modification;
use ReynoTECH\Approvals\Models\ModificationItem;
use ReynoTECH\Approvals\Tests\Fixtures\ApproverModel;
use ReynoTECH\Approvals\Tests\Stress\ConcurrentGroupedModel;
use ReynoTECH\Approvals\Tests\Stress\ConcurrentModel;
use ReynoTECH\Approvals\Tests\TestCase;

require __DIR__ . '/../bootstrap.php';

$job = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);

$capsule = new Capsule();
$capsule->addConnection(TestCase::connectionConfig());
$capsule->getContainer()->singleton('events', fn () => new Illuminate\Events\Dispatcher());
$capsule->setAsGlobal();
$capsule->bootEloquent();

// Connect and load everything before the barrier, so all of them start from the same point.
$capsule->getConnection()->getPdo();
$actor = isset($job['user']) ? ApproverModel::findOrFail($job['user']) : null;
$modification = isset($job['modification']) ? Modification::findOrFail($job['modification']) : null;
$item = isset($job['item']) ? ModificationItem::findOrFail($job['item']) : null;
$class = ($job['grouped'] ?? false) ? ConcurrentGroupedModel::class : ConcurrentModel::class;
$model = isset($job['model']) ? $class::findOrFail($job['model']) : null;

while (microtime(true) < $job['at']) {
    usleep(50);
}

$result = ['ok' => null, 'error' => null];

try {
    $result['ok'] = match ($job['action']) {
        'approve' => $actor->approve($modification, $job['reason'] ?? null),
        'disapprove' => $actor->disapprove($modification, $job['reason'] ?? null),
        'approveItem' => $actor->approveItem($item),
        'force' => (function () use ($modification) {
            $modification->forceApprovalUpdate();

            return true;
        })(),
        'hold' => (function () use ($model, $job) {
            $model->gate = true;
            $model->forceFill($job['changes'])->save();

            return true;
        })(),
    };
} catch (Throwable $e) {
    $result['error'] = get_class($e) . ': ' . $e->getMessage();
}

echo json_encode($result), "\n";
