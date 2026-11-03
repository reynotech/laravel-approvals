---
title: Creation Approval
---

# Creation Approval

[← Home](index.md)

By default a new model saves directly. To hold some creations until they're approved, implement `GatesCreation` and say which ones:

```php
use ReynoTECH\Approvals\Concerns\RequiresApprovalWhenChanges;
use ReynoTECH\Approvals\Contracts\Approvable;
use ReynoTECH\Approvals\Contracts\GatesCreation;

class Client extends Model implements Approvable, GatesCreation
{
    use RequiresApprovalWhenChanges;

    public function requiresApprovalOnCreate(array $attributes): bool
    {
        return ($attributes['type'] ?? null) === 'wholesale';
    }
}
```

Votes work as for any change: `approve()`/`disapprove()`, `approversRequired()`, `authorizedToApprove()`. A creation is decided as a whole, so `approvableItemGroups()` doesn't apply.

## Choosing a mode

|  | `deferred` (default) | `draft` |
|---|---|---|
| While pending | No row exists | The row exists, hidden from queries |
| `$client->id` after `create()` | `null`, and `exists` is `false` | Set, and `exists` is `true` |
| Related records before approval | Not possible | Possible |
| Migration on the model's table | None | A boolean column |
| Unique indexes | Checked when approved | Checked when requested, and the draft holds its values |
| When rejected | Nothing to clean up | The row is deleted (soft deleted if the model uses `SoftDeletes`) |

Use `deferred` unless you need the `id` or related records before approval.

Set the mode for the whole app in `config/approvals.php`:

```php
'creation' => [
    'mode' => 'deferred',                    // or 'draft'
    'draft_column' => 'approval_pending',
],
```

Or per model, which takes precedence:

```php
public function approvalCreationMode(): string
{
    return GatesCreation::DRAFT;
}
```

`approvalDraftColumn()` can be overridden the same way.

## Deferred mode

```php
$client = Client::create(['name' => 'ACME', 'type' => 'wholesale']);

$client->exists;                 // false: check this before using $client->id
$client->getLastModification();  // the pending request

foreach (Client::pendingCreations() as $request) {
    $user->approve($request);    // inserts the row
}

$request->fresh()->modifiable;   // the new Client
```

- `saving` fires on the request and again on approval. `creating`/`created` fire only on approval.
- `created_at` is the request time, the `occurred_at` of the `Modification`, as in draft mode. A `created_at` you set on the model is kept instead. `updated_at` is the approval time.
- Requesting the same creation again while one is pending returns that request instead of adding another.
- A failure on approval, such as a unique index, reaches the caller. The vote is undone and the request stays pending.

## Draft mode

Add the column to each table in draft mode:

```php
$table->boolean('approval_pending')->default(false)->index();
```

```php
$client = Client::create(['name' => 'ACME', 'type' => 'wholesale']);

$client->id;                                  // set
Client::find($client->id);                    // null: hidden until approved
Client::withApprovalDrafts()->find($client->id);
Client::onlyApprovalDrafts()->get();

$request = $client->getLastModification();
$user->approve($request);                     // the row becomes visible
```

- The scope covers Eloquent queries, relations and route model binding. Joins and the query builder (`DB::table()`) still see drafts, so filter those yourself.
- Approving reveals the row as it is at that moment, so edits made to a draft are kept.
- Related records aren't deleted with a rejected draft unless your foreign keys or listeners do it.
- An update to a draft goes through the usual gate and audit, as for any other row.
- Keep the column out of mass assignment: writing `false` to it reveals the row with no vote. Add it to `$guarded`, or leave it out of `$fillable`.

## Resolving without a vote

When a request no longer needs a decision, for example because the data it was waiting on got fixed, apply it with `forceApprovalCreate()`:

```php
foreach (Client::pendingCreations() as $request) {
    if (Region::whereKey($request->modifications['region_id']['mod'])->exists()) {
        $request->forceApprovalCreate();
    }
}
```

- The package watches nothing: your code decides when to call it.
- It works in both modes and only on pending requests, and throws a `LogicException` on a change to an existing row. It records no vote, so keep who or what resolved it yourself if you need that.
- It returns nothing. If `$request->fresh()->status` is still `pending`, a listener vetoed the write. A database error, such as a unique index, is thrown, and the request stays pending.
- It writes the values as they were requested. To change one, reject the request and send it again.

## Changing mode

Pending rows are decided by their own shape, so they can still be approved after a change. A model leaving draft mode stops hiding its rows, though, so approve or reject its drafts first.

Next: [Recording Manual Events](manual-events.md).
