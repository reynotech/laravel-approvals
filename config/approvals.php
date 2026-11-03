<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    |
    | Override these if your application needs to extend the package's base
    | models (e.g. to add extra relations or casts). Anything extending the
    | base class will keep working with RequiresApprovalWhenChanges/ApprovesChanges.
    |
    */
    'models' => [
        'modification' => \ReynoTECH\Approvals\Models\Modification::class,
        'modification_item' => \ReynoTECH\Approvals\Models\ModificationItem::class,
        'approval' => \ReynoTECH\Approvals\Models\Approval::class,
        'disapproval' => \ReynoTECH\Approvals\Models\Disapproval::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Diff keys
    |--------------------------------------------------------------------------
    |
    | Each entry in `modifications.modifications` is stored as a pair of
    | original/modified values, e.g. ['ori' => 'draft', 'mod' => 'active'].
    | Rows waiting for approval add 'raw' when what a save would store isn't
    | exactly `mod`: that's what approving writes. Rename the keys here if they
    | collide with something in your app, once no row is pending: rows already
    | stored keep the old names, and approving one can't read them.
    |
    */
    'diff_keys' => [
        'original' => 'ori',
        'modified' => 'mod',
        'stored' => 'raw',
    ],

    /*
    |--------------------------------------------------------------------------
    | Creation approval
    |--------------------------------------------------------------------------
    |
    | How a model implementing Contracts\GatesCreation holds a creation until
    | it's approved. Override approvalCreationMode() to choose per model.
    |
    | - 'deferred': nothing is inserted until it's approved. No column needed.
    | - 'draft': the row is inserted at once, hidden from queries until approved,
    |   and deleted if rejected. Needs `draft_column` on the model's table.
    |
    */
    'creation' => [
        'mode' => 'deferred',
        'draft_column' => 'approval_pending',
    ],
];
