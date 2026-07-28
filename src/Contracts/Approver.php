<?php

declare(strict_types=1);

namespace ReynoTECH\Approvals\Contracts;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use ReynoTECH\Approvals\Models\Modification;

/**
 * Implemented by models that use the ApprovesChanges concern: models that
 * can cast an approval/disapproval on a pending Modification.
 */
interface Approver
{
    public function approve(Modification $modification, ?string $reason = null): bool;

    public function disapprove(Modification $modification, ?string $reason = null): bool;

    public function approvals(): MorphMany;

    public function disapprovals(): MorphMany;
}
