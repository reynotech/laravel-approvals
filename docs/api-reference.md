---
title: API Reference
---

# API Reference

[← Home](index.md)

## Extension points

- `modifier()` — override to change how the "who made this change" resolver works (defaults to `auth()->user()`).
- `authorizedToApprove()` / `authorizedToDisapprove()` — override on the approver model to add role/policy checks.
- `approversRequired(): int` / `disapproversRequired(): int` — override to change how many votes decide a change (default 1).
- `requiresApprovalOnCreate(array $attributes): bool` — on a model implementing `Contracts\GatesCreation`, says which creations need [approval](creation-approval.md). `approvalCreationMode()` and `approvalDraftColumn()` override the `approvals.creation.*` config per model.
- `approvableItemGroups(array $dirtyKeys): array` — override to opt a model into [per-item approval](partial-approval.md). Returns `['group' => ['field', ...]]`; defaults to `[]` (no items, legacy whole-modification behavior).
- `config('approvals.models.*')` — swap in your own `Modification`/`ModificationItem`/`Approval`/`Disapproval` subclasses.
- `config('approvals.diff_keys.*')` — rename the `ori`/`mod` keys used in the stored diff.
- `Concerns\HasModificationMedia` — optional trait to attach images to a pending `Modification` via `spatie/laravel-medialibrary` (not a hard dependency of this package).
- Diff application (`applyModificationChanges`/`applyModificationItemChanges`) merges into an attribute whose current value exposes `set($key, $value)` and can be queried with `where()` (e.g. `spatie/laravel-schemaless-attributes`) instead of overwriting it outright. A `Fluent` is replaced whole.

## Public methods

On a model using `RequiresApprovalWhenChanges`:

| Method | Purpose |
|---|---|
| `requiresApproval(callable $callback)` | Gate this instance with an ad-hoc callback on every later save. When it returns `false`, `requiresApprovalWhenTrait()` still decides. |
| `noSaveModifications()` | Skip recording an audit `Modification` for a direct save. |
| `withModificationMeta($meta)` | Attach `meta` to the next `Modification` produced by this save. |
| `withFullSaveModification()` / `withFullSaveOnlyUpdateModification()` | Record the full attribute set instead of just the dirty diff. |
| `forceModification()` | Force an audit `Modification` even when the diff is empty. A save the gate holds isn't audited. |
| `withOperationId(?string $id)` | Tag the next `Modification`(s) with a shared `operation_id`. |
| `recordModification(...)` | See [Recording Manual Events](manual-events.md). |
| `getLastModification()` | The last `Modification` this instance created or matched. A save that creates none leaves it as it was. |
| `creations()` / `pendingCreations()` (static) | `Modification` rows for this model's creations: all of them, or those waiting for a decision. |

On a model using `ApprovesChanges`:

| Method | Purpose |
|---|---|
| `approve(Modification $m, ?string $reason = null)` | Decide the whole modification, or every pending item if it has any. |
| `disapprove(Modification $m, ?string $reason = null)` | Same, rejecting. |
| `approveItem(ModificationItem $i, ?string $reason = null)` | Decide a single item without touching the others. |
| `disapproveItem(ModificationItem $i, ?string $reason = null)` | Same, rejecting. |

All four return `false` when the vote wasn't taken or the change couldn't be applied: the approver isn't authorized, the change was already decided (a rejection is final, send the change again), it's a direct audit row, its model no longer exists (a pending change can still be rejected), or a listener vetoed the save. Each approver holds one vote per decision: voting again keeps the first reason, and a vote can switch sides while the change is undecided.

On a `Modification`:

| Method | Purpose |
|---|---|
| `forceApprovalUpdate()` | Apply a pending change or creation with no vote. All or nothing, and returns nothing: check `status` afterwards. |
| `forceApprovalCreate()` | The same for a creation. Throws a `LogicException` on anything else. |
| `isPending()` / `isCreation()` | Whether it's waiting for a decision, and whether it requests a new row. |
| `modifiableWithDrafts()` | The model, including a row hidden while its creation is pending. |

## Known limitations

- Migrations use `nullableMorphs()`/`morphs()`, which assume integer/bigint primary keys on `modifiable`/`modifier`/`approver`/`disapprover`. UUID/ULID-keyed models are not supported out of the box.
- `deleteWhenApproved` / `deleteWhenDisapproved` don't apply to modifications with items.
- Only `save()` goes through the gate. `increment()`/`decrement()`, `saveQuietly()`, `Model::where()->update()` and the query builder skip it, as they skip model events.
- A schemaless sub-key containing `.` or `*` is merged by `set()` as a path, so it doesn't arrive as a literal key.
- A change identical to a pending one is treated as that one, even if one of its items was already rejected: resend it once the rest is decided.
- A non-finite float (`INF`, `NAN`) can't be stored in the JSON diff, and a save holding one throws.
- `down()` of the first migration drops the tables, even if they existed before it ran.
- Votes and modifications soft deleted before adopting this package, in tables with a `deleted_at` column, still count.
- `saving` listeners run again on approval, and any that isn't idempotent (hashing a value it didn't check) applies twice. A save the gate holds doesn't reach listeners registered after this package's, which include those in `booted()` and observers, so they run only on approval.
- A raw SQL expression (`DB::raw()`) can't be held: it throws, since it has no value to store until it runs. Saved directly, it's audited as its SQL. A change to a model loaded without its key throws too.
- `$model->timestamps = false` on the instance doesn't reach the approval, which saves a fresh one.
- An approval applies to the row, and returns the state of its own instances: a `Modification` you already hold needs `fresh()` to show the result, and relations set on a held instance (`associate()`) stay in memory.
- `mod` is read during `saving`, so a listener changing the value afterwards is reflected in the column and not in a direct save's audit row.
- If the model and `modifications` live on different connections, a failure after writing the model can't undo that write, since the transaction covers only the `Modification`'s connection.
- Eloquent has no composite keys, so a model simulating them is identified by `getKey()` alone.
- Requesting the same deferred creation twice at the same instant can leave two requests, since no row exists yet to wait on. Each one decides on its own, and a unique index on the model's table still protects the data.
- `recordModification()` keeps one entry per `key`: a repeated key overwrites the earlier one.
- `ori` is `null` for a column changed without having been selected (e.g. after `select('id', 'name')`), since Eloquent never saw its value.
- A `{}` nested in an `array`/`json` attribute comes back as `[]`, as it does whenever Eloquent reads and saves that value.

## Deprecations

- `deleteWhenApproved` / `deleteWhenDisapproved` are deprecated: prefer letting the `Modification`/`ModificationItem` row stay and simply reading its `status`. They still work exactly as before.

## Credits

- Inspired in part by [cloudcake/laravel-approval](https://github.com/cloudcake/laravel-approval).

Next: [Upgrading](upgrading.md).
