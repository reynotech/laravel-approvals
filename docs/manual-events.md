---
title: Recording Manual Events
---

# Recording Manual Events

[← Home](index.md)

Automatic capture happens on `saving`, for attribute updates. For events that don't go through that hook — creation, deletion, attachments, signatures, calls to external integrations — use `recordModification()`.

It always records a `direct`/`applied` entry (it documents something that already happened; it doesn't gate anything), can register an event with no attribute diff at all, and runs inside whatever transaction the caller is already in.

```php
$model->recordModification(
    eventType: 'attachment_added',
    items: [
        [
            'key' => 'attachments.contract',
            'original' => null,
            'proposed' => ['id' => 123, 'name' => 'contract.pdf'],
        ],
    ],
    context: ['label' => 'Contract'],
    operationId: $operationId,
);
```

- `eventType` — a free-form label stored on `Modification::event_type`.
- `items` — each entry becomes one key in the stored diff, in the same `original`/`proposed` shape used everywhere else.
- `context` — arbitrary JSON metadata for the event.
- `operationId` — correlate this record with other `Modification` rows from the same logical operation (see [`operation_id`](modifications.md#correlating-an-operation-operation_id)).

Next: [API Reference](api-reference.md).
