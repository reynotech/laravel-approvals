---
title: Upgrading
---

# Upgrading

[← Home](index.md)

## From < 0.3.0

Publish and run the new migration:

```bash
php artisan vendor:publish --tag=approvals-migrations
php artisan migrate
```

It adds `flow`, `status`, `event_type`, `operation_id`, `context`, `occurred_at` to `modifications`, creates `modification_items`, and adds a nullable `modification_item_id` to `approvals`/`disapprovals`.

Existing rows are backfilled automatically: `flow`/`status` are derived from `active`/`is_modification`, and from vote counts for already-resolved rows.

**No consumer code changes are required.** `active`, `is_modification`, `approve()`, `disapprove()`, and the legacy diff format all keep working unchanged. `modification_items` stays empty unless a model opts in via [`approvableItemGroups()`](partial-approval.md).
