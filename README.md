## ReynoTECH Laravel Approvals

Approval workflows for Eloquent models: capture a pending change as a `Modification`, gate it behind N approvers/disapprovers, and apply the diff once approved.

Extracted from `rex-server-next`'s `RequiresApprovalWhenChanges` / `ApprovesChanges` traits so the workflow can be unit tested in isolation and reused across apps.

### Requirements
- PHP 8.2+
- `illuminate/database` / `illuminate/support` ^11 || ^12

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

### Extension points
- `modifier()` — override to change how the "who made this change" resolver works (defaults to `auth()->user()`).
- `authorizedToApprove()` / `authorizedToDisapprove()` — override on the approver model to add role/policy checks.
- `config('approvals.models.*')` — swap in your own `Modification`/`Approval`/`Disapproval` subclasses.
- `Concerns\HasModificationMedia` — optional trait to attach images to a pending `Modification` via `spatie/laravel-medialibrary` (not a hard dependency of this package).
- Diff application (`applyModificationChanges`) writes back to any attribute whose current value is an object exposing a `set($key, $value)` method (e.g. `spatie/laravel-schemaless-attributes`) instead of overwriting it outright.

### Known limitations
- Migration uses `nullableMorphs()`/`morphs()`, which assume integer/bigint primary keys on `modifiable`/`modifier`/`approver`/`disapprover`. UUID/ULID-keyed models are not supported out of the box.
- Only updates go through the approval gate; model creation always saves directly (matching the behavior this package was extracted from).

### Testing
```bash
composer test
```
