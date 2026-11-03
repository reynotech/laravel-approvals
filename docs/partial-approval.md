---
title: Partial (Per-Item) Approval
---

# Partial (Per-Item) Approval

[← Home](index.md)

By default a pending `Modification` is decided **as a whole**: `approve($modification)`/`disapprove($modification)` apply or discard the entire diff, and no `ModificationItem` rows are created. This keeps the common case (a single field, or "all or nothing") free of extra rows — this package favors audit history over searchability, so it never creates a row it doesn't need.

## Opting in

When an operation can touch independently-decidable groups of fields — e.g. `rules` can be approved while `notes` is rejected in the same save — override `approvableItemGroups()`:

```php
class Report extends Model implements Approvable
{
    use RequiresApprovalWhenChanges;

    protected function approvableItemGroups(array $dirtyKeys): array
    {
        return [
            'rules' => ['rules'],
            'notes' => ['notes'],
        ];
    }
}
```

Any dirty key **not** covered by a group goes, together with the other uncovered keys, into one more item keyed `ModificationItem::KEY_UNGROUPED` (`_ungrouped`), so `approve()`/`disapprove()` decide them along with the rest. Keys covered by a group each get one `ModificationItem` row **per group**, not per field — a group can bundle several fields into a single JSON `original`/`proposed` pair, keeping row counts low.

## Deciding items independently

```php
$modification = $report->modifications()->activeOnly()->first();

$rulesItem = $modification->items()->where('key', 'rules')->first();
$notesItem = $modification->items()->where('key', 'notes')->first();

$user->approveItem($rulesItem, 'rules look right');   // only `rules` is written back
$user->disapproveItem($notesItem, 'notes are wrong');  // `notes` is left untouched

$modification->fresh()->status; // 'partially_resolved'
```

Each decision records author, reason, and timestamp on the `Approval`/`Disapproval` row, scoped to that item via `modification_item_id`. Duplicate votes are collapsed and a user can change their vote, same as the global flow.

## Interoperability with the global flow

`approve($modification)`/`disapprove($modification)` stay interoperable with item-level decisions:

- If the `Modification` has **no** items → decides the whole thing (legacy behavior, unchanged).
- If the `Modification` **has** items → resolves every still-pending item atomically, in one transaction, without the caller needing to know `ModificationItem` exists.

## When an item resolves

- Reaching `approvers_required` on an item applies **only that item's** fields to the model and marks it `applied`.
- Reaching `disapprovers_required` on an item marks it `rejected` without touching the model.
- After every decision, the parent's `status` is recalculated from its items: `pending` while any item is still pending, `approved` once all are applied/approved, `rejected` once all are rejected, `partially_resolved` for a mix.

Next: [Creation Approval](creation-approval.md).
