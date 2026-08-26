---
title: Home
---

# Laravel Approvals

Approval workflows for Eloquent models: capture a pending change as a `Modification`, gate it behind N approvers/disapprovers, and apply the diff once approved.

`Modification` is the single source of change history and approval flow for a model. A direct update is recorded as an applied, informational `Modification`; an update that requires authorization is captured as a pending one and applied only once it clears its approval threshold.

## Contents

- [Getting Started](getting-started.md) — installation and the minimal setup for a gated model and an approver model.
- [Modification & History](modifications.md) — how `flow`/`status` model history, and the stored diff format.
- [Partial (Per-Item) Approval](partial-approval.md) — opting a model into independently-decidable field groups via `ModificationItem`.
- [Recording Manual Events](manual-events.md) — `recordModification()` for history outside of `saving` (attachments, signatures, integrations).
- [API Reference](api-reference.md) — extension points, config, known limitations.
- [Upgrading](upgrading.md) — migrating an existing installation to the `flow`/`status`/`ModificationItem` model.
- [Changelog](changelog.md)

## Requirements

- PHP 8.2+
- `illuminate/database` / `illuminate/support` ^11 || ^12 || ^13 (Laravel 13 requires PHP 8.3+)

## Installation

```bash
composer require reynotech/laravel-approvals
php artisan vendor:publish --tag=approvals-migrations
php artisan migrate
```

Optionally publish the config:

```bash
php artisan vendor:publish --tag=approvals-config
```

Source on GitHub: [reynotech/laravel-approvals](https://github.com/reynotech/laravel-approvals)
