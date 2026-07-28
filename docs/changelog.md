---
title: Changelog
---

# Changelog

[← Home](index.md)

> Mirrors [`CHANGELOG.md`](https://github.com/reynotech/laravel-approvals/blob/main/CHANGELOG.md) at the repository root.

All notable changes to this project are documented here. This project follows [Semantic Versioning](https://semver.org/).

### Unreleased
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
- Initial extraction from `rex-server-next`: `RequiresApprovalWhenChanges` / `ApprovesChanges` concerns, `Modification`/`Approval`/`Disapproval` models, config-based model resolution, optional `HasModificationMedia` trait, and a Capsule-based PHPUnit test suite.
