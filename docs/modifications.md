---
title: Modification & History
---

# Modification & History

[← Home](index.md)

`Modification` is the single source of history and approval state for a model. Every save produces one, whether or not it goes through approval.

## `flow` and `status`

| flow       | status                                        | active | is_modification |
|------------|------------------------------------------------|--------|------------------|
| `direct`   | `applied`                                       | false  | false            |
| `approval` | `pending`                                       | true   | true             |
| `approval` | `approved` / `rejected` / `partially_resolved`  | false  | true             |

`active`/`is_modification` are kept for backward compatibility and are **derived** from `flow`/`status` automatically — you never need to set them yourself. `flow`/`status` are the preferred contract going forward.

- `flow: direct` — the change was saved immediately; the `Modification` is a passive audit record.
- `flow: approval` — the change is (or was) gated behind approvers/disapprovers.
- `status: partially_resolved` — only reachable when the modification has [`ModificationItem`](partial-approval.md) rows: some were approved/applied and others rejected.

## The stored diff

`Modification::$modifications` stores one entry per changed attribute, each as a pair of the original and modified value:

```php
[
    'status' => ['ori' => 'draft', 'mod' => 'active'],
]
```

- `ori` — the attribute's value before the change (from `getOriginal()`/`getRawOriginal()`).
- `mod` — the attribute's value after the change, i.e. what gets written back once approved.

For array-like/schemaless attributes, `ori`/`mod` only contain the sub-keys that actually changed rather than the whole structure.

The `ori`/`mod` key names can be renamed via config, in case they collide with something in your app:

```php
// config/approvals.php
'diff_keys' => [
    'original' => 'ori',
    'modified' => 'mod',
],
```

## Additional metadata

`Modification` also carries `event_type` (nullable — set by [`recordModification()`](manual-events.md)), `operation_id` (nullable, indexed — see below), `context` (nullable JSON), and `occurred_at`.

## Correlating an operation (`operation_id`)

Call `withOperationId($id)` before saving (or pass `operationId` to `recordModification()`) to tag every `Modification` produced by one logical operation with the same id, so they can be correlated later even if some were applied directly and others went through approval.

Next: [Partial (Per-Item) Approval](partial-approval.md).
