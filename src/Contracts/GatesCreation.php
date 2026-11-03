<?php

declare(strict_types=1);

namespace ReynoTECH\Approvals\Contracts;

/**
 * Implemented by models whose creation can need approval. They also use the
 * RequiresApprovalWhenChanges concern, which handles the two available modes:
 *
 * - DEFERRED: nothing is inserted until the creation is approved.
 * - DRAFT: the row is inserted at once, hidden from queries until approved, and
 *   deleted if rejected. It needs a boolean column on the model's table.
 *
 * The mode comes from `approvals.creation.mode`; override
 * approvalCreationMode() to choose it per model.
 */
interface GatesCreation
{
    public const DEFERRED = 'deferred';

    public const DRAFT = 'draft';

    /**
     * Whether creating a model with these attributes needs approval.
     */
    public function requiresApprovalOnCreate(array $attributes): bool;

    public function approvalCreationMode(): string;

    public function approvalDraftColumn(): string;
}
