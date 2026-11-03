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
    private Capsule $capsule;

    /**
     * SQLite in memory by default. Set DB_CONNECTION to mysql or pgsql (see phpunit.mysql.xml and
     * phpunit.pgsql.xml) to run the same suite on a real server, whose locks, column types and JSON
     * handling SQLite doesn't have.
     */
    public static function connectionConfig(): array
    {
        $server = [
            'host' => getenv('DB_HOST') ?: '127.0.0.1',
            'database' => getenv('DB_DATABASE') ?: 'approvals_test',
            'password' => getenv('DB_PASSWORD') ?: 'testing',
            'prefix' => '',
        ];

        return match (getenv('DB_CONNECTION')) {
            'mysql' => $server + [
                'driver' => 'mysql',
                'port' => getenv('DB_PORT') ?: '33071',
                'username' => getenv('DB_USERNAME') ?: 'root',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'strict' => true,
                'engine' => 'InnoDB',
            ],
            'pgsql' => $server + [
                'driver' => 'pgsql',
                'port' => getenv('DB_PORT') ?: '33072',
                'username' => getenv('DB_USERNAME') ?: 'postgres',
                'charset' => 'utf8',
                'search_path' => 'public',
                'sslmode' => 'disable',
            ],
            default => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        };
    }

    /**
     * A real server, as opposed to SQLite in memory.
     */
    public static function usesServer(): bool
    {
        return in_array(getenv('DB_CONNECTION'), ['mysql', 'pgsql'], true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        test_set_authenticated_user(null);
        test_set_config('approvals.models.modification', \ReynoTECH\Approvals\Models\Modification::class);
        test_set_config('approvals.models.approval', \ReynoTECH\Approvals\Models\Approval::class);
        test_set_config('approvals.models.disapproval', \ReynoTECH\Approvals\Models\Disapproval::class);
        test_set_config('approvals.models.modification_item', \ReynoTECH\Approvals\Models\ModificationItem::class);

        $capsule = new Capsule();
        $capsule->addConnection(self::connectionConfig());
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
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($capsule->getContainer());

        $this->capsule = $capsule;

        if ($capsule->getConnection()->getDriverName() !== 'sqlite') {
            $capsule->getConnection()->getSchemaBuilder()->dropAllTables();
        }

        (require __DIR__ . '/../database/migrations/2024_01_01_000000_create_approvals_tables.php')->up();
        (require __DIR__ . '/../database/migrations/2024_01_02_000000_add_flow_and_modification_items.php')->up();
        (require __DIR__ . '/../database/migrations/2024_01_03_000000_add_approval_lookup_indexes.php')->up();
        (require __DIR__ . '/Fixtures/create_fixture_tables.php')();
    }

    /**
     * A diff read back from the database. A MySQL `json` column keeps its own key order, so only the
     * order of lists means anything there. The same goes for any engine's `jsonb`.
     */
    protected function assertDiffSame(array $expected, array $actual, string $message = ''): void
    {
        $this->assertSame(self::canonicalKeyOrder($expected), self::canonicalKeyOrder($actual), $message);
    }

    private static function canonicalKeyOrder(array $value): array
    {
        $value = array_map(fn ($entry) => is_array($entry) ? self::canonicalKeyOrder($entry) : $entry, $value);

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    protected function tearDown(): void
    {
        // A server, unlike SQLite in memory, runs out of connections if each test keeps its own.
        foreach (array_keys($this->capsule->getDatabaseManager()->getConnections()) as $name) {
            $this->capsule->getDatabaseManager()->disconnect($name);
        }

        parent::tearDown();
    }
}
