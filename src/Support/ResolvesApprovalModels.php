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
    public static function modificationClass(): string
    {
        return config('approvals.models.modification', Modification::class);
    }

    public static function modificationItemClass(): string
    {
        return config('approvals.models.modification_item', ModificationItem::class);
    }

    public static function approvalClass(): string
    {
        return config('approvals.models.approval', Approval::class);
    }

    public static function disapprovalClass(): string
    {
        return config('approvals.models.disapproval', Disapproval::class);
    }
}
