---
title: Modification & History
---

# Modification & History

[← Home](index.md)

`Modification` is the single source of history and approval state for a model. Every update that changes something produces one, whether or not it goes through approval. A direct create doesn't, and neither does a save of an instance using `noSaveModifications()`.

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

- `ori` — the attribute's value before the change, in the same format as `mod`, so a `mod` matches the `ori` of the next change to that attribute.
- `mod` — the attribute's value after the change, for reading.
- `raw` — only on rows waiting for approval, and only when `mod` isn't exactly what a save would store: the stored value, which is what approving writes. A cast read back can differ (a `decimal:2` rounds `10.555`, a `date` drops the time), so going through the setter wouldn't reach what saving directly would have.

Attributes with an accessor or mutator, an enum, `encrypted*`, `object`, `timestamp` or custom class cast keep their **stored** form in `ori`/`mod` too (the raw column value, so an encrypted attribute stays encrypted in the diff). The exception is a custom cast returning something array-like, which reads as an array. A value that isn't valid UTF-8 (a binary column) reads as `{"__base64": "..."}`.

Every cast Eloquent ships is covered by the same promise: approving stores exactly what saving directly would have, and a real change always leaves a row, including removing a key at any depth from an array, `json`, `collection` or `AsCollection`/`AsArrayObject`/`AsFluent` attribute. Those hold the whole value on both sides, and a change of key order counts. The exceptions are `AsBinary` and `AsUri`, which need packages this one doesn't depend on and aren't tested, and `AsVector` on MariaDB, which stores a raw SQL expression and so can't be held.

A schemaless attribute lists only the sub-keys that changed, and approving merges them, so other sub-keys changed since the request are kept. A removed sub-key appears in `ori` and not in `mod`, and that change adds `raw`: approving it writes the whole value.

Columns listed in `noTrackModification` stay out of the audit trail, but when one changes alongside a tracked column in a gated save it's held in the diff too, so approving doesn't lose it. A gated save that changes only untracked columns (or only `updated_at`, as `touch()` does) has nothing to decide and saves directly.

For array and collection attributes, `ori`/`mod` hold the whole value, since that's what gets written back. Attributes exposing a `set($key, $value)` method (e.g. `spatie/laravel-schemaless-attributes`) are the exception: `ori`/`mod` only contain the sub-keys that changed, and those are merged on approval.

The `ori`/`mod` key names can be renamed via config, in case they collide with something in your app:

```php
// config/approvals.php
'diff_keys' => [
    'original' => 'ori',
    'modified' => 'mod',
    'stored' => 'raw',
],
```

Rename them only with no row pending: rows already stored keep the old names, and approving one can't read them.

## Additional metadata

`Modification` also carries `event_type` (nullable — set by [`recordModification()`](manual-events.md)), `operation_id` (nullable, indexed — see below), `context` (nullable JSON), and `occurred_at`.

## Correlating an operation (`operation_id`)

Call `withOperationId($id)` before saving (or pass `operationId` to `recordModification()`) to tag every `Modification` produced by one logical operation with the same id, so they can be correlated later even if some were applied directly and others went through approval.

Next: [Partial (Per-Item) Approval](partial-approval.md).
