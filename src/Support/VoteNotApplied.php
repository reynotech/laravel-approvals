<?php

declare(strict_types=1);

namespace ReynoTECH\Approvals\Support;

/**
 * Undoes a whole decision from inside its transaction when one of its items couldn't be
 * applied, so approve() can't leave a modification half decided. Never reaches the caller.
 */
final class VoteNotApplied extends \RuntimeException
{
}
