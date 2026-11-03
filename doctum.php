<?php

// The generated class reference: docs/api. Build it with `bin/bench api-docs`.

use Doctum\Doctum;
use Doctum\RemoteRepository\GitHubRemoteRepository;
use Symfony\Component\Finder\Finder;

return new Doctum(Finder::create()->files()->name('*.php')->in(__DIR__ . '/src'), [
    'title' => 'Laravel Approvals API',
    'build_dir' => __DIR__ . '/docs/api',
    'cache_dir' => __DIR__ . '/build/doctum/cache',
    'source_dir' => __DIR__ . '/',
    'remote_repository' => new GitHubRemoteRepository('reynotech/laravel-approvals', __DIR__ . '/'),
    'base_url' => 'https://reynotech.github.io/laravel-approvals/api/',
    'default_opened_level' => 2,
]);
