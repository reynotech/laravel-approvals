<?php

declare(strict_types=1);

namespace ReynoTECH\Approvals\Contracts;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use ReynoTECH\Approvals\Models\Modification;

/**
 * Implemented by models that use the RequiresApprovalWhenChanges concern:
 * models whose changes can be captured as a pending Modification instead
 * of being saved directly.
 */
interface Approvable
{
    public function modifications(): MorphMany;

    public function applyModificationChanges(Modification $modification, bool $approved): void;
}
