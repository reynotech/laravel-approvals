<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

if (!function_exists('config')) {
    $GLOBALS['__test_config'] = [
        'approvals' => require __DIR__ . '/../config/approvals.php',
    ];

    function config($key = null, $default = null)
    {
        if (is_array($key)) {
            foreach ($key as $k => $v) {
                data_set($GLOBALS['__test_config'], $k, $v);
            }

            return null;
        }

        if ($key === null) {
            return $GLOBALS['__test_config'];
        }

        return data_get($GLOBALS['__test_config'], $key, $default);
    }
}

if (!function_exists('auth')) {
    $GLOBALS['__test_auth_user'] = null;

    function auth()
    {
        return new class {
            public function user()
            {
                return $GLOBALS['__test_auth_user'];
            }
        };
    }
}

// A server takes a moment to accept connections after its container starts.
if (in_array(getenv('DB_CONNECTION'), ['mysql', 'pgsql'], true)) {
    $config = ReynoTECH\Approvals\Tests\TestCase::connectionConfig();
    $dsn = "{$config['driver']}:host={$config['host']};port={$config['port']};dbname={$config['database']}";
    $deadline = time() + 90;

    while (true) {
        try {
            new PDO($dsn, $config['username'], $config['password']);
            break;
        } catch (PDOException $e) {
            if (time() > $deadline) {
                fwrite(STDERR, "{$config['driver']} isn't reachable at {$config['host']}:{$config['port']}. Start it with `bin/bench db up`.\n{$e->getMessage()}\n");
                exit(1);
            }

            sleep(1);
        }
    }
}

function test_set_authenticated_user($user): void
{
    $GLOBALS['__test_auth_user'] = $user;
}

function test_set_config(string $key, $value): void
{
    data_set($GLOBALS['__test_config'], $key, $value);
}
