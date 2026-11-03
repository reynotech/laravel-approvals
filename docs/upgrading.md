---
title: Upgrading
---

# Upgrading

[← Home](index.md)

## From < 0.6.0

Resolve pending `Modification` rows before upgrading if they touch an `encrypted` attribute: older rows hold its plain text in `mod`, and approving one now writes that to the column as is. Existing pending rows with ungrouped fields also keep losing them, and votes duplicated by different reasons, or stored under a class name before adding a morph map, still count separately.

- A rejected change can no longer be approved afterwards: send it again. `approve()`/`disapprove()` return `false` for any change that's already decided, and on a direct audit row.
- `ori`/`mod` hold the stored form of attributes with an accessor or mutator, an enum, `encrypted*`, `object`, `timestamp` or custom class cast. Code reading `mod` for display must cast it itself.
- With a morph map, votes and `modifier_type` now use the alias. Votes stored earlier under the class name aren't found by `$user->approvals()`.
- A model with `approvableItemGroups()` gets an extra `_ungrouped` item when a change touches fields outside its groups.
- The audit row of a direct update is written right after the row, so an `updating` listener no longer sees it through `getLastModification()`. An `updated` listener does.
- A veto during approval now undoes the vote. An approver voting again gets the same result, but code counting votes after a refused approval will see none.
- Holding a raw SQL expression, or a change to a model loaded without its key, now throws a `LogicException`.
- After a gated save the instance holds the stored values, not the proposed ones. Code reading `$model->field` after a held `save()` must read `$model->getLastModification()` instead.
- `ori` now uses the same format as `mod` (a `date` is `Y-m-d`, a `boolean` is `true`/`false`). Rows already stored keep the old format, so code comparing `ori` against a live value should expect both.
- Rows waiting for approval can hold a third key, `raw`. Code reading the diff should read `ori`/`mod` by name rather than assume a pair.
- `Modification::forceApprovalUpdate()` does nothing on a change that isn't pending.
- Creation approval is opt-in. To use `draft` mode, add its boolean column to the model's table (see [Creation Approval](creation-approval.md)).

No migration is required.

## From < 0.5.0

`Modification` rows already pending keep their old partial diff, and approving one still replaces the column with that fragment. Resolve them before upgrading.

For `array`, `collection`, `AsCollection` and `AsArrayObject` attributes, `ori`/`mod` now hold the whole value instead of the sub-keys that changed. Code reading sub-keys from them must compare both sides itself. Attributes exposing `set()` (e.g. schemaless attributes) keep their partial diff.

No migration is required.

## From < 0.3.0

Publish and run the new migration:

```bash
php artisan vendor:publish --tag=approvals-migrations
php artisan migrate
```

It adds `flow`, `status`, `event_type`, `operation_id`, `context`, `occurred_at` to `modifications`, creates `modification_items`, and adds a nullable `modification_item_id` to `approvals`/`disapprovals`.

Existing rows are backfilled automatically: `flow`/`status` are derived from `active`/`is_modification`, and from vote counts for already-resolved rows.

**No consumer code changes are required.** `active`, `is_modification`, `approve()`, `disapprove()`, and the legacy diff format all keep working unchanged. `modification_items` stays empty unless a model opts in via [`approvableItemGroups()`](partial-approval.md).
