<?php

declare(strict_types=1);

namespace ReynoTECH\Approvals\Tests;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        test_set_authenticated_user(null);
        test_set_config('approvals.models.modification', \ReynoTECH\Approvals\Models\Modification::class);
        test_set_config('approvals.models.approval', \ReynoTECH\Approvals\Models\Approval::class);
        test_set_config('approvals.models.disapproval', \ReynoTECH\Approvals\Models\Disapproval::class);
        test_set_config('approvals.models.modification_item', \ReynoTECH\Approvals\Models\ModificationItem::class);

        $capsule = new Capsule();
        $capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $capsule->getContainer()->singleton('events', fn () => new Dispatcher());
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        // Capsule::bootEloquent() swaps in a fresh event dispatcher every test
        // but doesn't clear Eloquent's per-class "booted" flag, so model event
        // listeners (e.g. our `saving` hook) would only ever register once per
        // process and end up orphaned on a previous test's dispatcher.
        Model::clearBootedModels();

        $capsule->getContainer()->instance('db', $capsule->getDatabaseManager());
        $capsule->getContainer()->bind('db.schema', fn ($app) => $app['db']->connection()->getSchemaBuilder());
        Facade::setFacadeApplication($capsule->getContainer());

        (require __DIR__ . '/../database/migrations/2024_01_01_000000_create_approvals_tables.php')->up();
        (require __DIR__ . '/../database/migrations/2024_01_02_000000_add_flow_and_modification_items.php')->up();
        (require __DIR__ . '/Fixtures/create_fixture_tables.php')();
    }
}
