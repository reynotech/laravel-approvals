<?php

declare(strict_types=1);

namespace ReynoTECH\Approvals;

use Illuminate\Support\ServiceProvider;

/**
 * Registers the config and migrations of the package.
 */
class ApprovalsServiceProvider extends ServiceProvider
{
    /**
     * Merge the package config under the `approvals` key.
     *
     * @return void
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/approvals.php',
            'approvals'
        );
    }

    /**
     * Load the migrations, and offer them and the config for publishing.
     *
     * Publish tags: `approvals-config` and `approvals-migrations`.
     *
     * @return void
     */
    public function boot(): void
    {
        $this->publishes(
            [
                __DIR__ . '/../config/approvals.php' => config_path('approvals.php'),
            ],
            'approvals-config'
        );

        $this->publishes(
            [
                __DIR__ . '/../database/migrations' => database_path('migrations'),
            ],
            'approvals-migrations'
        );

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }
}
