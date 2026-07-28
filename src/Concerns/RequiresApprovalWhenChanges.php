<?php

declare(strict_types=1);

namespace ReynoTECH\Approvals\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use ReynoTECH\Approvals\Models\Modification;
use ReynoTECH\Approvals\Support\ResolvesApprovalModels;

/**
 * Applied to the model whose changes should go through an approval workflow.
 *
 * On `saving`, an update is either captured as a pending Modification (when
 * requiresApprovalWhen()/requiresApproval() says so), recorded as a full
 * audit Modification row (the default), or saved as-is.
 */
trait RequiresApprovalWhenChanges
{
    protected int $approversRequired = 1;

    protected int $disapproversRequired = 1;

    protected bool $updateWhenApproved = true;

    protected bool $deleteWhenDisapproved = false;

    protected bool $deleteWhenApproved = false;

    private bool $forcedApprovalUpdate = false;

    private bool $saveModifications = true;

    private bool $fullSaveModification = false;

    private bool $onlyInUpdate = false;

    private mixed $modificationMeta = false;

    private ?Modification $lastModification = null;

    private bool $forcedModification = false;

    private bool $modelApproval = false;

    private $modelApprovalFn = null;

    public static function bootRequiresApprovalWhenChanges(): void
    {
        static::saving(function ($item) {
            if (is_null($item->{$item->getKeyName()})) {
                return true;
            }

            if ($item->modelApproval && call_user_func_array($item->modelApprovalFn, [$item->getDirty()]) === true) {
                return self::captureSave($item);
            }

            if (!$item->isForcedApprovalUpdate() && $item->requiresApprovalWhen($item->getDirty()) === true) {
                return self::captureSave($item);
            }

            if ($item->saveModifications) {
                return self::captureModification($item);
            }

            $item->setForcedApprovalUpdate(false);

            return true;
        });
    }

    /**
     * Rule that decides when an approval process should be triggered for
     * this model. Override directly, or define a `requiresApprovalWhenTrait`
     * method to keep this concern free for other traits to compose.
     */
    protected function requiresApprovalWhen(array $modifications): bool
    {
        if (method_exists($this, 'requiresApprovalWhenTrait')) {
            return $this->requiresApprovalWhenTrait($modifications);
        }

        return false;
    }

    public function modifications(): MorphMany
    {
        return $this->morphMany(ResolvesApprovalModels::modificationClass(), 'modifiable');
    }

    public static function creations()
    {
        $modificationClass = ResolvesApprovalModels::modificationClass();

        return $modificationClass::whereModifiableType(static::class)->whereIsUpdate(false)->get();
    }

    /**
     * Returns the model that should be recorded as the author of the change.
     * Override this to plug in your app's auth resolution.
     */
    protected function modifier()
    {
        return auth()->user();
    }

    public function applyModificationChanges(Modification $modification, bool $approved): void
    {
        if ($approved && $this->updateWhenApproved) {
            $this->setForcedApprovalUpdate(true);

            $modifiedKey = self::modifiedDiffKey();

            foreach ($modification->modifications as $key => $mod) {
                $current = $this->{$key};

                if (is_object($current) && method_exists($current, 'set')) {
                    foreach ($mod[$modifiedKey] as $k => $v) {
                        $current->set($k, $v);
                    }
                } else {
                    $this->{$key} = $mod[$modifiedKey];
                }
            }

            $this->noSaveModifications();

            $this->save();

            if ($this->deleteWhenApproved) {
                $modification->delete();
            } else {
                $modification->active = false;
                $modification->save();
            }
        } elseif ($approved === false) {
            if ($this->deleteWhenDisapproved) {
                $modification->delete();
            } else {
                $modification->active = false;
                $modification->save();
            }
        }
    }

    public function isForcedApprovalUpdate(): bool
    {
        return $this->forcedApprovalUpdate;
    }

    public function setForcedApprovalUpdate(bool $forced = true): bool
    {
        return $this->forcedApprovalUpdate = $forced;
    }

    public function requiresApproval(callable $callback): static
    {
        $this->modelApproval = true;
        $this->modelApprovalFn = $callback;

        return $this;
    }

    public function noSaveModifications(): static
    {
        $this->saveModifications = false;

        return $this;
    }

    public function withModificationMeta($meta): static
    {
        $this->modificationMeta = $meta;

        return $this;
    }

    public function withFullSaveModification(): static
    {
        $this->fullSaveModification = true;

        return $this;
    }

    public function withFullSaveOnlyUpdateModification(): static
    {
        $this->fullSaveModification = true;
        $this->onlyInUpdate = true;

        return $this;
    }

    public function forceModification(): static
    {
        $this->forcedModification = true;

        return $this;
    }

    public function getLastModification(): ?Modification
    {
        return $this->lastModification;
    }

    public function setLastModification(?Modification $mod): static
    {
        $this->lastModification = $mod;

        return $this;
    }

    public static function captureSave($item): bool
    {
        $diff = self::buildModificationDiff($item);

        $hasModificationPending = $item->modifications()
            ->activeOnly()
            ->where('md5', md5(json_encode($diff)))
            ->first();

        $modificationModel = ResolvesApprovalModels::modificationClass();
        $modification = $hasModificationPending ?? new $modificationModel();

        self::fillModificationModel(
            item: $item,
            modification: $modification,
            diff: $diff,
            active: true,
            isModification: true,
            approversRequired: (int) $item->approversRequired,
            disapproversRequired: (int) $item->disapproversRequired
        );

        if (!$hasModificationPending) {
            $item->modifications()->save($modification);
        }

        $item->setLastModification($modification);

        return false;
    }

    public static function captureModification($item): bool
    {
        $diff = self::buildModificationDiff($item);

        if ($diff || $item->forcedModification) {
            $modificationModel = ResolvesApprovalModels::modificationClass();
            $modification = new $modificationModel();

            self::fillModificationModel(
                item: $item,
                modification: $modification,
                diff: $diff,
                active: false,
                isModification: false,
                approversRequired: 0,
                disapproversRequired: 0
            );

            $item->modifications()->save($modification);

            $item->setLastModification($modification);
        }

        return true;
    }

    /**
     * Builds the diff payload stored in modifications.modifications.
     * All diffs are treated as multidimensional.
     */
    private static function buildModificationDiff($item): array|false
    {
        $getReal = function ($key, $val) {
            $m = new self();
            $ma = new self();
            $ma->{$key} = $val;
            $m->setRawAttributes([$key => $ma->getAttributes()[$key]]);

            return $m->{$key};
        };

        $reject = $item->noTrackModification ?? [];

        $originalKey = self::originalDiffKey();
        $modifiedKey = self::modifiedDiffKey();

        $diffFn = fn ($modified, $original) => array_diff_multidimensional($modified, $original);

        $buildRow = function ($key) use ($item, $getReal, $diffFn, $originalKey, $modifiedKey) {
            $original = $item->getOriginal($key);

            if (is_array($original)) {
                $d = $diffFn($getReal($key, $item->{$key}), $original);

                return [
                    $originalKey => collect($original)->where(fn ($v, $k) => in_array($k, array_keys($d)))->toArray(),
                    $modifiedKey => collect($getReal($key, $item->{$key}))->where(fn ($v, $k) => in_array($k, array_keys($d)))->toArray(),
                ];
            }

            if ($original instanceof \ArrayAccess) {
                $d = $diffFn($getReal($key, $item->{$key})->toArray(), $original->toArray());

                return [
                    $originalKey => $original->where(fn ($v, $k) => in_array($k, array_keys($d)))->toArray(),
                    $modifiedKey => $getReal($key, $item->{$key})->where(fn ($v, $k) => in_array($k, array_keys($d)))->toArray(),
                ];
            }

            return [
                $originalKey => $item->getRawOriginal($key),
                $modifiedKey => $item->{$key} instanceof \DateTime ? $item->{$key}->format('Y-m-d') : $item->{$key},
            ];
        };

        $isNoopChange = function ($key) use ($item, $getReal, $diffFn) {
            $original = $item->getOriginal($key);

            if (is_array($original)) {
                return count($diffFn($getReal($key, $item->{$key}), $original)) === 0;
            }

            if ($original instanceof \ArrayAccess) {
                return count($diffFn($getReal($key, $item->{$key})->toArray(), $original->toArray())) === 0;
            }

            return $original === $getReal($key, $item->{$key});
        };

        if ($item->fullSaveModification) {
            $dirty = $item->onlyInUpdate ? $item->getDirty() : true;

            return $dirty
                ? collect($item->getOriginal())
                    ->reject(fn ($change, $key) => in_array($key, $reject))
                    ->transform(fn ($change, $key) => $buildRow($key))
                    ->all()
                : false;
        }

        return collect($item->getDirty())
            ->reject(fn ($change, $key) => in_array($key, $reject))
            ->reject(fn ($change, $key) => $isNoopChange($key))
            ->transform(fn ($change, $key) => $buildRow($key))
            ->all();
    }

    private static function fillModificationModel(
        $item,
        $modification,
        $diff,
        bool $active,
        bool $isModification,
        int $approversRequired,
        int $disapproversRequired
    ): void {
        $modifier = $item->modifier();

        $modification->active = $active;
        $modification->modifications = $diff;
        $modification->approvers_required = $approversRequired;
        $modification->disapprovers_required = $disapproversRequired;
        $modification->md5 = md5(json_encode($diff));
        $modification->is_modification = $isModification;

        if ($modifier && ($modifierClass = get_class($modifier))) {
            $modifierInstance = new $modifierClass();

            $modification->modifier_id = $modifier->{$modifierInstance->getKeyName()};
            $modification->modifier_type = $modifierClass;
        }

        if (is_null($item->{$item->getKeyName()})) {
            $modification->is_update = false;
        }

        if ($item->modificationMeta) {
            $modification->meta = $item->modificationMeta;
        }
    }

    /**
     * Key used to store the original value in each diff row.
     * Configurable via `approvals.diff_keys.original` (defaults to 'ori').
     */
    private static function originalDiffKey(): string
    {
        return config('approvals.diff_keys.original', 'ori');
    }

    /**
     * Key used to store the modified value in each diff row.
     * Configurable via `approvals.diff_keys.modified` (defaults to 'mod').
     */
    private static function modifiedDiffKey(): string
    {
        return config('approvals.diff_keys.modified', 'mod');
    }
}
