<?php

declare(strict_types=1);

namespace ReynoTECH\Approvals\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use ReynoTECH\Approvals\Contracts\GatesCreation;

/**
 * Hides rows waiting for their creation to be approved on models in draft mode.
 * The mode is read per query, so a model in deferred mode never needs the column.
 */
class ApprovalDraftScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if ($model->approvalCreationMode() === GatesCreation::DRAFT) {
            $builder->where($model->qualifyColumn($model->approvalDraftColumn()), false);
        }
    }

    public function extend(Builder $builder): void
    {
        $builder->macro('withApprovalDrafts', fn (Builder $builder) => $builder->withoutGlobalScope($this));

        $builder->macro('onlyApprovalDrafts', function (Builder $builder) {
            $model = $builder->getModel();

            return $builder->withoutGlobalScope($this)
                ->where($model->qualifyColumn($model->approvalDraftColumn()), true);
        });
    }
}
