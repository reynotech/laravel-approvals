## ReynoTECH Laravel Approvals

Approval workflows for Eloquent models: capture a pending change as a `Modification`, gate it behind N approvers/disapprovers, and apply the diff once approved.

Extracted from `rex-server-next`'s `RequiresApprovalWhenChanges` / `ApprovesChanges` traits so the workflow can be unit tested in isolation and reused across apps.

📖 Full documentation: **https://reynotech.github.io/laravel-approvals/**

### Requirements
- PHP 8.2+
- `illuminate/database` / `illuminate/support` ^11 || ^12 || ^13 (Laravel 13 requires PHP 8.3+)

### Installation
```bash
composer require reynotech/laravel-approvals
php artisan vendor:publish --tag=approvals-migrations
php artisan migrate
```

Optionally publish the config:
```bash
php artisan vendor:publish --tag=approvals-config
```

### Usage

On the model whose changes should be gated:
```php
use ReynoTECH\Approvals\Concerns\RequiresApprovalWhenChanges;
use ReynoTECH\Approvals\Contracts\Approvable;

class Client extends Model implements Approvable
{
    use RequiresApprovalWhenChanges;

    protected int $approversRequired = 1;
    protected int $disapproversRequired = 1;

    // Decide when a change needs approval instead of saving directly.
    protected function requiresApprovalWhenTrait(array $dirty): bool
    {
        return array_key_exists('billing_address', $dirty);
    }
}
```

On the model that casts approvals (e.g. `User`):
```php
use ReynoTECH\Approvals\Concerns\ApprovesChanges;
use ReynoTECH\Approvals\Contracts\Approver;

class User extends Authenticatable implements Approver
{
    use ApprovesChanges;
}
```

```php
$client->billing_address = $newAddress;
$client->save(); // captured as a pending Modification instead of persisted

$modification = $client->modifications()->activeOnly()->first();

$user->approve($modification, 'confirmed with client');
// once approvers_required is reached, the diff is applied and the row is updated
```

### The modification diff

`Modification::$modifications` stores one entry per changed attribute, each as a pair of the original and modified value:

```php
[
    'status' => ['ori' => 'draft', 'mod' => 'active'],
]
```

- `ori` — the attribute's value before the change (from `getOriginal()`/`getRawOriginal()`).
- `mod` — the attribute's value after the change, i.e. what gets written back once approved.

For array and collection attributes, `ori`/`mod` hold the whole value, since that's what gets written back. Attributes exposing a `set($key, $value)` method (e.g. `spatie/laravel-schemaless-attributes`) are the exception: `ori`/`mod` only contain the sub-keys that changed, and those are merged on approval.

The `ori`/`mod` key names can be renamed via config, in case they collide with something in your app:
```php
// config/approvals.php
'diff_keys' => [
    'original' => 'ori',
    'modified' => 'mod',
],
```

### `Modification` as the single source of history

Every save produces a `Modification`: `flow` is `direct` or `approval`, and `status` is one of `applied`, `pending`, `approved`, `rejected` or `partially_resolved`. `active`/`is_modification` are kept and still work exactly as before (they're derived from `flow`/`status`), but `flow`/`status` are the preferred contract going forward:

| flow       | status               | active | is_modification |
|------------|-----------------------|--------|------------------|
| `direct`   | `applied`             | false  | false            |
| `approval` | `pending`              | true   | true             |
| `approval` | `approved`/`rejected`/`partially_resolved` | false  | true             |

### Per-item (partial) approval — opt-in

By default a pending `Modification` is decided as a whole, exactly like before: `approve($modification)`/`disapprove($modification)` apply or discard the entire diff, and no `ModificationItem` rows are created. This keeps the common case (a single field, or "all or nothing") free of any extra rows — this package favors audit history over searchability, so it never creates a row it doesn't need.

When an operation can touch independently-decidable groups of fields (e.g. `rules` can be approved while `notes` is rejected in the same save), override `approvableItemGroups()` to opt in:

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

Any dirty key not covered by a group stays bundled into the parent `Modification`'s own diff. Groups that are covered get one `ModificationItem` row each (not one row per field — a group can bundle several fields into a single JSON `original`/`proposed` pair, keeping row counts low), decided independently:

```php
$modification = $report->modifications()->activeOnly()->first();
$rulesItem = $modification->items()->where('key', 'rules')->first();
$notesItem = $modification->items()->where('key', 'notes')->first();

$user->approveItem($rulesItem, 'rules look right');   // only `rules` is written back
$user->disapproveItem($notesItem, 'notes are wrong');  // `notes` is left untouched

$modification->fresh()->status; // 'partially_resolved'
```

`approve($modification)`/`disapprove($modification)` stay interoperable with this: called on a `Modification` that has items, they resolve every still-pending item atomically in one transaction, instead of requiring the caller to know about `ModificationItem` at all.

### Recording history outside of `save()`

For events that don't go through Eloquent's `saving` hook — creation, deletion, attachments, signatures, calls to external integrations — use `recordModification()`. It always records a `direct`/`applied` entry (it documents something that already happened; it doesn't gate anything) and runs inside whatever transaction the caller is already in:

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
    context: ['label' => 'Contrato'],
    operationId: $operationId,
);
```

### `operation_id`

Pass `withOperationId($id)` before saving (or to `recordModification()`) to tag every `Modification` produced by one logical operation with the same `operation_id`, so they can be correlated later even if some were applied directly and others went through approval.

### Extension points
- `modifier()` — override to change how the "who made this change" resolver works (defaults to `auth()->user()`).
- `authorizedToApprove()` / `authorizedToDisapprove()` — override on the approver model to add role/policy checks.
- `config('approvals.models.*')` — swap in your own `Modification`/`ModificationItem`/`Approval`/`Disapproval` subclasses.
- `config('approvals.diff_keys.*')` — rename the `ori`/`mod` keys used in the stored diff (see above).
- `Concerns\HasModificationMedia` — optional trait to attach images to a pending `Modification` via `spatie/laravel-medialibrary` (not a hard dependency of this package).
- Diff application (`applyModificationChanges`) writes back to any attribute whose current value is an object exposing a `set($key, $value)` method (e.g. `spatie/laravel-schemaless-attributes`) instead of overwriting it outright.

### Known limitations
- Migration uses `nullableMorphs()`/`morphs()`, which assume integer/bigint primary keys on `modifiable`/`modifier`/`approver`/`disapprover`. UUID/ULID-keyed models are not supported out of the box.
- Only updates go through the approval gate; model creation always saves directly (matching the behavior this package was extracted from).
- Removing a key from an attribute exposing `set()` (e.g. a schemaless attribute), or clearing it, produces an empty diff, so the change is lost. When gated, it leaves a pending `Modification` that changes nothing when approved.

### Deprecations
- `deleteWhenApproved` / `deleteWhenDisapproved` are deprecated: prefer letting the `Modification`/`ModificationItem` row stay and simply reading its `status`. They still work exactly as before.

### Upgrading from < 0.5.0
`Modification` rows already pending keep their old partial diff, and approving one still replaces the column with that fragment. Resolve them before upgrading.

For `array`, `collection`, `AsCollection` and `AsArrayObject` attributes, `ori`/`mod` now hold the whole value instead of the sub-keys that changed. Code reading sub-keys from them must compare both sides itself. Attributes exposing `set()` (e.g. schemaless attributes) keep their partial diff.

No migration is required.

### Upgrading from < 0.3.0
Publish and run the new migration:
```bash
php artisan vendor:publish --tag=approvals-migrations
php artisan migrate
```
It adds `flow`, `status`, `event_type`, `operation_id`, `context`, `occurred_at` to `modifications`, creates `modification_items`, and adds a nullable `modification_item_id` to `approvals`/`disapprovals`. Existing rows are backfilled automatically (`flow`/`status` derived from `active`/`is_modification`, and from vote counts for already-resolved rows). No consumer code changes are required: `active`, `is_modification`, `approve()`, `disapprove()` and the legacy diff format all keep working unchanged, and `modification_items` stays empty unless a model opts in via `approvableItemGroups()`.

### Testing
```bash
composer test
```

### Credits
- Inspired in part by [cloudcake/laravel-approval](https://github.com/cloudcake/laravel-approval).
