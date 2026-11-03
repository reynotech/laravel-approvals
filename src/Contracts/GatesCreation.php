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
    /**
     * No row exists until the creation is approved.
     */
    public const DEFERRED = 'deferred';

    /**
     * The row exists from the start, hidden from queries until approved.
     */
    public const DRAFT = 'draft';

    /**
     * Whether creating a model with these attributes needs approval.
     */
    public function requiresApprovalOnCreate(array $attributes): bool;

    /**
     * How a creation is held until it's approved.
     *
     * @return string  Either GatesCreation::DEFERRED or GatesCreation::DRAFT.
     */
    public function approvalCreationMode(): string;

    /**
     * The boolean column marking a row as waiting for approval in draft mode.
     *
     * @return string
     */
    public function approvalDraftColumn(): string;
}
