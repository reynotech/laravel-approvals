<?php

declare(strict_types=1);

namespace ReynoTECH\Approvals\Tests\Stress;

class ConcurrentGroupedModel extends ConcurrentModel
{
    public const GROUPS = ['status' => ['status'], 'notes' => ['notes'], 'name' => ['name']];
}
