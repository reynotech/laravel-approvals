---
title: API Reference
---

# API Reference

[← Home](index.md)

## Extension points

- `modifier()` — override to change how the "who made this change" resolver works (defaults to `auth()->user()`).
- `authorizedToApprove()` / `authorizedToDisapprove()` — override on the approver model to add role/policy checks.
- `approvableItemGroups(array $dirtyKeys): array` — override to opt a model into [per-item approval](partial-approval.md). Returns `['group' => ['field', ...]]`; defaults to `[]` (no items, legacy whole-modification behavior).
- `config('approvals.models.*')` — swap in your own `Modification`/`ModificationItem`/`Approval`/`Disapproval` subclasses.
- `config('approvals.diff_keys.*')` — rename the `ori`/`mod` keys used in the stored diff.
- `Concerns\HasModificationMedia` — optional trait to attach images to a pending `Modification` via `spatie/laravel-medialibrary` (not a hard dependency of this package).
- Diff application (`applyModificationChanges`/`applyModificationItemChanges`) writes back to any attribute whose current value is an object exposing a `set($key, $value)` method (e.g. `spatie/laravel-schemaless-attributes`) instead of overwriting it outright.

## Public methods

On a model using `RequiresApprovalWhenChanges`:

| Method | Purpose |
|---|---|
| `requiresApproval(callable $callback)` | Gate this save with an ad-hoc callback instead of `requiresApprovalWhenTrait()`. |
| `noSaveModifications()` | Skip recording an audit `Modification` for a direct save. |
| `withModificationMeta($meta)` | Attach `meta` to the next `Modification` produced by this save. |
| `withFullSaveModification()` / `withFullSaveOnlyUpdateModification()` | Record the full attribute set instead of just the dirty diff. |
| `forceModification()` | Force an audit `Modification` even when the diff is empty. |
| `withOperationId(?string $id)` | Tag the next `Modification`(s) with a shared `operation_id`. |
| `recordModification(...)` | See [Recording Manual Events](manual-events.md). |
| `getLastModification()` | The `Modification` created by the most recent save. |

On a model using `ApprovesChanges`:

| Method | Purpose |
|---|---|
| `approve(Modification $m, ?string $reason = null)` | Decide the whole modification, or every pending item if it has any. |
| `disapprove(Modification $m, ?string $reason = null)` | Same, rejecting. |
| `approveItem(ModificationItem $i, ?string $reason = null)` | Decide a single item without touching the others. |
| `disapproveItem(ModificationItem $i, ?string $reason = null)` | Same, rejecting. |

## Known limitations

- Migrations use `nullableMorphs()`/`morphs()`, which assume integer/bigint primary keys on `modifiable`/`modifier`/`approver`/`disapprover`. UUID/ULID-keyed models are not supported out of the box.
- Only updates go through the approval gate; model creation always saves directly (matching the behavior this package was extracted from).

## Deprecations

- `deleteWhenApproved` / `deleteWhenDisapproved` are deprecated: prefer letting the `Modification`/`ModificationItem` row stay and simply reading its `status`. They still work exactly as before.

## Credits

- Inspired in part by [cloudcake/laravel-approval](https://github.com/cloudcake/laravel-approval).

Next: [Upgrading](upgrading.md).
