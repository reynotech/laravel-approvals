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

function test_set_authenticated_user($user): void
{
    $GLOBALS['__test_auth_user'] = $user;
}

function test_set_config(string $key, $value): void
{
    data_set($GLOBALS['__test_config'], $key, $value);
}
