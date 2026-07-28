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
    | Rename the keys here if 'ori'/'mod' collide with something in your app.
    |
    */
    'diff_keys' => [
        'original' => 'ori',
        'modified' => 'mod',
    ],
];
