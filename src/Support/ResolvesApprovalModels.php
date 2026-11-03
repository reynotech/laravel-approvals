<?php

declare(strict_types=1);

namespace ReynoTECH\Approvals\Support;

use ReynoTECH\Approvals\Models\Approval;
use ReynoTECH\Approvals\Models\Disapproval;
use ReynoTECH\Approvals\Models\Modification;
use ReynoTECH\Approvals\Models\ModificationItem;

/**
 * Resolves the model classes to use for modifications/approvals/disapprovals,
 * honoring the `approvals.models.*` config so consuming apps can swap in
 * their own subclasses without forking the package.
 */
class ResolvesApprovalModels
{
    /**
     * The class to use for modifications, from `approvals.models.modification`.
     *
     * @return class-string<\ReynoTECH\Approvals\Models\Modification>
     */
    public static function modificationClass(): string
    {
        return config('approvals.models.modification', Modification::class);
    }

    /**
     * The class to use for modification items, from `approvals.models.modification_item`.
     *
     * @return class-string<\ReynoTECH\Approvals\Models\ModificationItem>
     */
    public static function modificationItemClass(): string
    {
        return config('approvals.models.modification_item', ModificationItem::class);
    }

    /**
     * The class to use for approvals, from `approvals.models.approval`.
     *
     * @return class-string<\ReynoTECH\Approvals\Models\Approval>
     */
    public static function approvalClass(): string
    {
        return config('approvals.models.approval', Approval::class);
    }

    /**
     * The class to use for disapprovals, from `approvals.models.disapproval`.
     *
     * @return class-string<\ReynoTECH\Approvals\Models\Disapproval>
     */
    public static function disapprovalClass(): string
    {
        return config('approvals.models.disapproval', Disapproval::class);
    }
}
