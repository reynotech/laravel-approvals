---
title: Changelog
---

# Changelog

[← Home](index.md)

> Mirrors [`CHANGELOG.md`](https://github.com/reynotech/laravel-approvals/blob/main/CHANGELOG.md) at the repository root.

All notable changes to this project are documented here. This project follows [Semantic Versioning](https://semver.org/).

### 0.6.0 - 2026-11-03
- Fixed: one approver could fill several approval slots by voting with different reasons. Each approver now holds one vote per decision, and the first reason is kept.
- Fixed: a change could be voted on after being decided. Reapproving overwrote newer data, disapproving marked an applied change `rejected`, and approving a direct audit row rolled the model back. All of them now return `false` and change nothing.
- **Changed:** a rejection is final. Approving a `rejected` change or item no longer applies it; send the change again. A vote can still switch sides while the change is undecided.
- Fixed: approving a modification with items silently lost the fields outside every group. They now go into an extra item keyed `_ungrouped`.
- Fixed: `encrypted` attributes were stored in plain text in `mod`.
- Fixed: a direct save recorded an `applied` audit row even if a listener or the database then refused it. The row is now written after the save.
- **Changed:** attributes with an accessor or mutator, an enum, `encrypted*`, `timestamp` or custom class cast keep their stored form in `ori`/`mod`, and approving writes it back as is. Before, a mutator ran twice, an accessor's output was written to the column, the `timestamp` cast wrote an integer, and value objects threw.
- Fixed: `AsFluent` attributes gained a `where` key and couldn't lose keys when approved. They're now stored whole.
- Fixed: approving reported `approved` when a listener vetoed the save. The change stays pending and the call returns `false`.
- Fixed: a failure while applying a change left the vote behind. The vote and its effect now share a transaction, with the row locked.
- Fixed: full-save modifications overwrote columns changed since the request, lost changed columns that hadn't been selected, and threw when nothing was dirty.
- Fixed: a gated save with nothing to decide (no changes, `touch()`, only `noTrackModification` columns) left an empty pending row and blocked the save. It now saves directly, and untracked columns changed alongside tracked ones are held in the diff instead of lost.
- Fixed: saving threw for a model extending an abstract base using the trait, an accessor reading another attribute, `AsStringable`, non-backed enums, and binary values.
- Fixed: approving threw when the model no longer existed, or when a `deleteWhenApproved` row was approved again. It now returns `false`, and the pending change can still be rejected.
- Fixed: morph maps were ignored by votes, `modifier_type` and `creations()`.
- Fixed: creating a model with an explicit primary key went through the gate and the audit trail as if it were an update.
- Fixed: `requiresApproval()` ignored forced updates, so approving could open a new pending row; `setForcedApprovalUpdate()` stayed active past one save; `withModificationMeta()` repeated on later saves and lost scalar values; and auditing stayed off on the instance after `forceApprovalUpdate()`.
- Fixed: `forceApprovalUpdate()` left items pending, `updateWhenApproved = false` left the row pending forever, and `approversRemaining`/`disapproversRemaining` counted item votes.
- Fixed: a held change stayed dirty on the instance, so its next save carried it along. A later gated save could apply it after it had been rejected, and an ungated one wrote it with no approval.
- **Changed:** after a gated save the instance holds the stored values again. Read the proposed ones from `getLastModification()`.
- Fixed: array changes smaller than the differ's float tolerance, or only reordering keys, produced an empty diff: saved with no audit row when direct, and approved into nothing when gated. Whole values are now compared exactly.
- **Changed:** `ori` uses the same format as `mod`. A `date` no longer reads `2026-01-01 00:00:00` on one side and `2026-01-01` on the other, a `boolean` `1` and `true`, or a cleared `AsCollection` the string `"null"`.
- Fixed: non-backed enums inside `AsEnumCollection`/`AsEnumArrayObject` threw on every save.
- **Changed:** the `object` cast keeps its stored JSON in `ori`/`mod`, so an empty object is no longer written back as `[]`.
- **Changed:** rows waiting for approval add `raw` when what a save would store isn't exactly `mod`, and approving writes it. Approving now stores exactly what saving directly would have. Before, it rounded `decimal` values, truncated `integer` casts, lost a change of time on a `date`, ran mutators again on array casts, and threw on accessors returning arrays and on custom casts validating their input.
- Fixed: columns named like Eloquent's own properties (`hidden`, `table`, `visible`, `connection`, `original`...) were stored wrong and never written.
- Fixed: user data shaped like the binary marker (`__base64`) was decoded on approval, replacing the column.
- Fixed: setting an unselected column to `null` produced an empty diff and was never written.
- Fixed: `Modification`/`ModificationItem` subclasses with another class name crashed on approve (wrong foreign key). The README promises those can be swapped in.
- Fixed: a draft's request was lost when a `created` listener saved the model, leaving a hidden row nobody could decide.
- Fixed: a retry after a failed draft insert kept the row hidden even when it no longer needed approval.
- Fixed: approving a modification with items wasn't atomic when a listener vetoed one: it returned `false` with other items already written.
- Fixed: rejecting a draft whose delete was vetoed reported `rejected` with the row still there. It now stays pending and returns `false`.
- Fixed: `forceApprovalUpdate()` applied rejected changes and rejected items, and rolled the model back from audit rows. It now only acts on what's pending.
- Fixed: a gated `restore()` could never be approved, because a trashed model counted as gone.
- Fixed: a soft-deleting `Modification` subclass with `deleteWhenApproved` reported failure and could be applied again.
- Fixed: full saves with item groups created items for untouched columns, leaving the row pending forever.
- Fixed: `recordModification()` threw on binary values, and a manual event after `delete()` counted as a creation.
- Fixed: the meta of a repeated request reached the next one, the same deferred creation with its attributes in another order made two requests, and the `add_flow_and_modification_items` migration couldn't roll back on SQLite.
- Fixed: removing a key from a schemaless attribute is now recorded, and approved by writing the whole value. It was a known limitation since 0.5.0.
- Fixed: a `created` listener saving a gated model wiped the instance, down to its id. A listener saving during a save or an approval no longer requests over again what was just written.
- Fixed: a model deleted and saved again under a creation gate requested nothing, and approving inserted a blank row.
- Fixed: a raw SQL expression held for approval was written as `[]`, and a model loaded without its key inserted a second row on approval. Both now throw when held.
- Fixed: saving threw on an enum value since removed from its enum, on an accessor needing an unselected column, and on an enum assigned to an uncasted column.
- Fixed: a model on another connection looked for its modifications there while they were saved on the default one, and the reverse from `Modification`.
- **Changed:** a vetoed write undoes the whole decision, its vote included, on every path. `forceApprovalUpdate()` is all or nothing too, and restores the instance's status if it fails.
- **Changed:** the audit row of an update is written right after the row, so `updated` listeners can read it with `getLastModification()`.
- Fixed: in a schemaless attribute, removing a nested key or changing only the key order left an empty diff, and a change smaller than a float tolerance was missed. Sub-keys are now compared exactly.
- Removed: the `rogervila/array-diff-multidimensional` dependency, no longer used.
- Fixed: the same change requested twice at the same instant was held twice. Requests for one row now wait for each other.
- Fixed: on Postgres, saving a model with a changed `bytea` column threw, since it reads back as a stream, and approving bytes failed because they were written as text.
- Added: creation approval. A model implementing `Contracts\GatesCreation` can hold a creation until it's approved, either with no row (`deferred`, the default) or with a hidden one (`draft`, which needs a boolean column). Set the mode in `approvals.creation.mode` or per model with `approvalCreationMode()`. Models that don't opt in save directly as before.
- Added: `approversRequired()`/`disapproversRequired()` to override on the model, since redeclaring the trait's properties with another value is a PHP fatal error.

### 0.5.0 - 2026-11-02
- Fixed: approving a change to a date attribute (`date`, `datetime`, `immutable_datetime`) never wrote it, including when clearing it to `null`. Carbon has its own `set()`, so the date was treated as a schemaless attribute: PHP warned on the `foreach` over the proposed string, which a Laravel app turns into an `ErrorException` with the vote saved and the change still pending. Attributes exposing `set()` are now merged key by key only when the proposed value is an array.
- Fixed: approving a change to an `array`, `collection`, `AsCollection` or `AsArrayObject` attribute replaced the column with only the part that changed, losing the rest. Removing elements or keys recorded nothing: a gated change left a pending row with an empty diff that changed nothing when approved, and a direct one saved without an audit row. `AsArrayObject` attributes threw while building the diff.
- **Changed:** for those attributes, `ori`/`mod` now hold the whole value instead of the changed sub-keys. Code reading sub-keys from `ori`/`mod` must compare the two sides itself. Attributes exposing `set()` (e.g. schemaless attributes) keep their partial diff.
- **Upgrading:** `Modification` rows already pending keep their old partial diff, and approving one still replaces the column with that fragment. Resolve them before upgrading.

### 0.4.2 - 2026-10-30
- Fixed: a `datetime`/`timestamp` attribute lost its time in the diff (it was always formatted `Y-m-d`). Only `date` casts keep `Y-m-d` now; any other date keeps the model's storage format.
- Fixed: setting an array/`ArrayAccess` attribute (e.g. a JSON column cast to `array`) to `null` or a scalar threw `$array1 must be an array!` while building the diff. It's now recorded as a whole replacement (`ori` = previous value, `mod` = new value).

### 0.4.1 - 2026-10-30
- Fixed: saving the same gated change while an identical one is still pending no longer refills the pending `Modification` in memory with the new requester/reason/date (without saving it). The original request is kept untouched and returned by `getLastModification()`.

### 0.4.0 - 2026-08-25
- Added support for Laravel 13 (`illuminate/*` ^13.0). Laravel 11 and 12 remain supported.
- Added idempotent lookup indexes for approval owners and pending-modification queries, including legacy installations whose pre-existing tables skipped the package's create migration.

### 0.3.1 - 2026-07-28
- Reworked the `create_approvals_tables` migration so it is idempotent on installations whose tables already exist.

### 0.3.0 - 2026-07-28
- `Modification` gains `flow` (`direct`/`approval`), `status` (`applied`/`pending`/`approved`/`rejected`/`partially_resolved`), `event_type`, `operation_id`, `context`, `occurred_at`. `active`/`is_modification` are now derived from `flow`/`status` and kept for backward compatibility.
- Added `ModificationItem` (`modification_items` table): an opt-in, per-group decidable unit inside a `Modification`. A model opts in by overriding `approvableItemGroups()`; groups store their diff as JSON `original`/`proposed` (like the existing diff format), not one row per field, to keep this an audit-friendly, low-row-count design.
- Added `modification_item_id` (nullable) to `approvals`/`disapprovals`: a vote scoped to an item decides only that item; a vote without it is a global decision, unchanged from before.
- Added `$user->approveItem()` / `$user->disapproveItem()`. `approve()`/`disapprove()` stay interoperable: they decide the whole `Modification` when it has no items (legacy behavior, unchanged), or resolve all pending items atomically when it has them.
- Added `$model->recordModification()`: an explicit history API for events outside of `saving` (creation, deletion, attachments, signatures, external integrations).
- Added `withOperationId()` to correlate multiple `Modification` rows produced by the same logical operation.
- Deprecated `deleteWhenApproved`/`deleteWhenDisapproved` (still functional).
- New migration `2024_01_02_000000_add_flow_and_modification_items.php`; backfills `flow`/`status` on existing `modifications` rows. See [Upgrading](upgrading.md).

### 0.2.0 - 2026-07-28
- Documented the `ori`/`mod` diff structure stored in `Modification::$modifications`.
- Made the `ori`/`mod` diff keys configurable via `config('approvals.diff_keys.*')`.

### 0.1.0 - 2026-07-28
- Initial release: `RequiresApprovalWhenChanges` / `ApprovesChanges` concerns, `Modification`/`Approval`/`Disapproval` models, config-based model resolution, optional `HasModificationMedia` trait, and a Capsule-based PHPUnit test suite.
