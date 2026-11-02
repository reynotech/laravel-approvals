<?php

declare(strict_types=1);

namespace ReynoTECH\Approvals\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use ReynoTECH\Approvals\Models\Modification;
use ReynoTECH\Approvals\Models\ModificationItem;
use ReynoTECH\Approvals\Support\ResolvesApprovalModels;

/**
 * Applied to the model whose changes should go through an approval workflow.
 *
 * On `saving`, an update is either captured as a pending Modification (when
 * requiresApprovalWhen()/requiresApproval() says so), recorded as a full
 * audit Modification row (the default), or saved as-is.
 *
 * A pending Modification is decided as a whole by approve()/disapprove()
 * unless the model opts into per-group decisions by overriding
 * approvableItemGroups(): in that case each group becomes a ModificationItem
 * that can be approved/rejected independently via approveItem()/disapproveItem().
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

    private ?string $operationId = null;

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

    /**
     * Opt-in: return the field groups that should be decided independently
     * of one another, e.g. ['rules' => ['rules'], 'notes' => ['notes']].
     * Keys not covered by any group stay bundled as a single decision on
     * the parent Modification (legacy behavior, no ModificationItem rows).
     */
    protected function approvableItemGroups(array $dirtyKeys): array
    {
        return [];
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

            self::applyLegacyDiff($this, $modification->modifications->toArray());

            $this->noSaveModifications();

            $this->save();

            if ($this->deleteWhenApproved) {
                $modification->delete();
            } else {
                $modification->status = Modification::STATUS_APPROVED;
                $modification->save();
            }
        } elseif ($approved === false) {
            if ($this->deleteWhenDisapproved) {
                $modification->delete();
            } else {
                $modification->status = Modification::STATUS_REJECTED;
                $modification->save();
            }
        }
    }

    /**
     * Applies (or rejects) a single ModificationItem's proposed values,
     * touching only the fields captured in that item.
     */
    public function applyModificationItemChanges(ModificationItem $item, bool $approved): void
    {
        if ($approved && $this->updateWhenApproved) {
            $this->setForcedApprovalUpdate(true);

            self::applyLegacyDiff($this, self::itemDiffToLegacyShape($item));

            $this->noSaveModifications();

            $this->save();

            $item->status = 'applied';
            $item->save();
        } elseif ($approved === false) {
            $item->status = 'rejected';
            $item->save();
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

    public function withOperationId(?string $operationId): static
    {
        $this->operationId = $operationId;

        return $this;
    }

    public function currentOperationId(): ?string
    {
        return $this->operationId;
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

    /**
     * Explicit history API for events that don't go through `saving`
     * (creation, deletion, attachments, signatures, external integrations).
     * Always recorded as an applied, direct Modification: it documents
     * something that already happened, it does not gate anything.
     *
     * $items entries look like: ['key' => 'attachments.contract', 'original' => null, 'proposed' => [...]].
     */
    public function recordModification(
        string $eventType,
        array $items = [],
        ?array $context = null,
        ?string $operationId = null,
        ?\DateTimeInterface $occurredAt = null
    ): Modification {
        $originalKey = self::originalDiffKey();
        $modifiedKey = self::modifiedDiffKey();

        $diff = [];
        foreach ($items as $entry) {
            $diff[$entry['key']] = [
                $originalKey => $entry['original'] ?? null,
                $modifiedKey => $entry['proposed'] ?? null,
            ];
        }

        $modificationModel = ResolvesApprovalModels::modificationClass();
        $modification = new $modificationModel();

        self::fillModificationModel(
            item: $this,
            modification: $modification,
            diff: $diff,
            flow: Modification::FLOW_DIRECT,
            status: Modification::STATUS_APPLIED,
            approversRequired: 0,
            disapproversRequired: 0,
            operationId: $operationId ?? $this->currentOperationId(),
            eventType: $eventType,
            context: $context,
            occurredAt: $occurredAt
        );

        $this->modifications()->save($modification);

        $this->setLastModification($modification);

        return $modification;
    }

    public static function captureSave($item): bool
    {
        $diff = self::buildModificationDiff($item);

        $hasModificationPending = $item->modifications()
            ->where('flow', Modification::FLOW_APPROVAL)
            ->where('status', Modification::STATUS_PENDING)
            ->where('md5', md5(json_encode($diff)))
            ->first();

        // The same change is already waiting for approval: don't duplicate or rewrite it. Refilling
        // it in memory (requester, reason, date) without saving left callers reporting data the
        // stored row didn't have. The original request wins.
        if ($hasModificationPending) {
            $item->setLastModification($hasModificationPending);

            return false;
        }

        $modificationModel = ResolvesApprovalModels::modificationClass();
        $modification = new $modificationModel();

        self::fillModificationModel(
            item: $item,
            modification: $modification,
            diff: $diff,
            flow: Modification::FLOW_APPROVAL,
            status: Modification::STATUS_PENDING,
            approversRequired: (int) $item->approversRequired,
            disapproversRequired: (int) $item->disapproversRequired,
            operationId: $item->currentOperationId()
        );

        $item->modifications()->save($modification);

        $groups = self::resolveApprovableGroups($item, $diff);

        if ($groups) {
            self::createItemsForGroups($modification, $groups, $item);
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
                flow: Modification::FLOW_DIRECT,
                status: Modification::STATUS_APPLIED,
                approversRequired: 0,
                disapproversRequired: 0,
                operationId: $item->currentOperationId()
            );

            $item->modifications()->save($modification);

            $item->setLastModification($modification);
        }

        return true;
    }

    /**
     * Splits the field groups declared by approvableItemGroups() into the
     * ones that actually changed in this diff, keyed by group name.
     */
    private static function resolveApprovableGroups($item, array $diff): array
    {
        $groups = $item->approvableItemGroups(array_keys($diff));

        return collect($groups)
            ->map(fn ($fields) => array_values(array_intersect($fields, array_keys($diff))))
            ->filter(fn ($fields) => count($fields) > 0)
            ->all();
    }

    private static function createItemsForGroups(Modification $modification, array $groups, $item): void
    {
        $itemModel = ResolvesApprovalModels::modificationItemClass();
        $originalKey = self::originalDiffKey();
        $modifiedKey = self::modifiedDiffKey();
        $diff = $modification->modifications->toArray();

        foreach ($groups as $key => $fields) {
            $original = [];
            $proposed = [];

            foreach ($fields as $field) {
                $row = $diff[$field] ?? null;

                if ($row === null) {
                    continue;
                }

                $original[$field] = $row[$originalKey];
                $proposed[$field] = $row[$modifiedKey];
            }

            $itemModel::updateOrCreate(
                ['modification_id' => $modification->id, 'key' => $key],
                [
                    'original' => $original,
                    'proposed' => $proposed,
                    'status' => 'pending',
                    'approvers_required' => (int) $item->approversRequired,
                    'disapprovers_required' => (int) $item->disapproversRequired,
                ]
            );
        }
    }

    /**
     * Applies a legacy-shaped diff (`['field' => ['ori' => ..., 'mod' => ...]]`)
     * onto a model, mutating only the fields present in the diff.
     */
    private static function applyLegacyDiff($model, array $diff): void
    {
        $modifiedKey = self::modifiedDiffKey();

        foreach ($diff as $key => $mod) {
            $current = $model->{$key};

            // Only a map of sub-keys can be merged: a date cast also has `set()` (Carbon's), but its
            // proposed value is a string or null, which replaces the attribute like any other.
            if (is_array($mod[$modifiedKey]) && is_object($current) && method_exists($current, 'set')) {
                foreach ($mod[$modifiedKey] as $k => $v) {
                    $current->set($k, $v);
                }
            } else {
                $model->{$key} = $mod[$modifiedKey];
            }
        }
    }

    private static function itemDiffToLegacyShape(ModificationItem $item): array
    {
        $originalKey = self::originalDiffKey();
        $modifiedKey = self::modifiedDiffKey();

        $diff = [];

        foreach ($item->proposed->toArray() as $key => $value) {
            $diff[$key] = [
                $originalKey => $item->original[$key] ?? null,
                $modifiedKey => $value,
            ];
        }

        return $diff;
    }

    /**
     * Builds the diff payload stored in modifications.modifications.
     * All diffs are treated as multidimensional.
     */
    /**
     * A `date` cast keeps `Y-m-d`; any other date (`datetime`, `timestamp`, custom formats) keeps its
     * time, in the model's own storage format, so the diff doesn't silently drop the hour.
     */
    private static function serializeDiffDate($item, string $key, \DateTimeInterface $value): string
    {
        $cast = strtolower((string) ($item->getCasts()[$key] ?? ''));
        $dateOnly = in_array($cast, ['date', 'immutable_date'], true)
            || str_starts_with($cast, 'date:')
            || str_starts_with($cast, 'immutable_date:');

        return $dateOnly ? $value->format('Y-m-d') : $item->fromDateTime($value);
    }

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

        // An array/ArrayAccess attribute replaced by a scalar or null (e.g. clearing a JSON column)
        // can't be diffed key by key: it's recorded as a whole replacement.
        $replacesStructure = function ($original, $modified): bool {
            $isStructure = fn ($value): bool => is_array($value) || $value instanceof \ArrayAccess;

            return $isStructure($original) && ! $isStructure($modified);
        };

        // Arrays and collections are written back whole, so that's what the diff keeps: positions or
        // sub-keys alone can't say what was removed, and approving them would replace the column with
        // just that fragment. Only an object exposing `set()` (a schemaless attribute) can be merged.
        $isWholeValue = fn ($value): bool => is_array($value)
            || ($value instanceof \ArrayAccess && ! method_exists($value, 'set'));

        $plain = fn ($value) => $value instanceof \ArrayAccess ? $value->toArray() : $value;

        $buildRow = function ($key) use ($item, $getReal, $diffFn, $originalKey, $modifiedKey, $replacesStructure, $isWholeValue, $plain) {
            $original = $item->getOriginal($key);
            $modified = $getReal($key, $item->{$key});

            if ($replacesStructure($original, $modified)) {
                return [
                    $originalKey => $original instanceof \ArrayAccess ? $original->toArray() : $original,
                    $modifiedKey => $modified,
                ];
            }

            if ($isWholeValue($original)) {
                return [
                    $originalKey => $plain($original),
                    $modifiedKey => $plain($modified),
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
                $modifiedKey => $item->{$key} instanceof \DateTimeInterface ? self::serializeDiffDate($item, $key, $item->{$key}) : $item->{$key},
            ];
        };

        $isNoopChange = function ($key) use ($item, $getReal, $diffFn, $replacesStructure, $isWholeValue, $plain) {
            $original = $item->getOriginal($key);

            if ($replacesStructure($original, $getReal($key, $item->{$key}))) {
                return false;
            }

            if ($isWholeValue($original)) {
                $before = $plain($original);
                $after = $plain($getReal($key, $item->{$key}));

                return count($diffFn($after, $before)) === 0 && count($diffFn($before, $after)) === 0;
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
        string $flow,
        string $status,
        int $approversRequired,
        int $disapproversRequired,
        ?string $operationId = null,
        ?string $eventType = null,
        ?array $context = null,
        ?\DateTimeInterface $occurredAt = null
    ): void {
        $modifier = $item->modifier();

        $modification->flow = $flow;
        $modification->status = $status;
        $modification->modifications = $diff;
        $modification->approvers_required = $approversRequired;
        $modification->disapprovers_required = $disapproversRequired;
        $modification->md5 = md5(json_encode($diff));
        $modification->occurred_at = $occurredAt ?? new \DateTimeImmutable();

        if ($operationId) {
            $modification->operation_id = $operationId;
        }

        if ($eventType) {
            $modification->event_type = $eventType;
        }

        if ($context !== null) {
            $modification->context = $context;
        }

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
