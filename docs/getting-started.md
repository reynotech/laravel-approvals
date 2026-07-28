---
title: Getting Started
---

# Getting Started

[← Home](index.md)

## On the gated model

Apply `RequiresApprovalWhenChanges` to the model whose changes should go through the workflow, and implement `Approvable`:

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

## On the approver model

Apply `ApprovesChanges` to the model that casts approvals/disapprovals (e.g. `User`), and implement `Approver`:

```php
use ReynoTECH\Approvals\Concerns\ApprovesChanges;
use ReynoTECH\Approvals\Contracts\Approver;

class User extends Authenticatable implements Approver
{
    use ApprovesChanges;
}
```

## Basic flow

```php
$client->billing_address = $newAddress;
$client->save(); // captured as a pending Modification instead of persisted

$modification = $client->modifications()->activeOnly()->first();

$user->approve($modification, 'confirmed with client');
// once approvers_required is reached, the diff is applied and the row is updated
```

An update that does **not** require approval is saved directly, and a full audit `Modification` (`flow: direct`, `status: applied`) is still recorded — no approval rows are created for it.

Next: [Modification & History](modifications.md).
