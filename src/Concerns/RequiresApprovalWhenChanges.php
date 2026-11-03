<?php

declare(strict_types=1);

namespace ReynoTECH\Approvals\Concerns;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Fluent;
use ReynoTECH\Approvals\Contracts\GatesCreation;
use ReynoTECH\Approvals\Models\Modification;
use ReynoTECH\Approvals\Models\ModificationItem;
use ReynoTECH\Approvals\Scopes\ApprovalDraftScope;
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
    private static string $binaryDiffKey = '__base64';

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

    private ?Modification $pendingAudit = null;

    private ?array $settledAttributes = null;

    public static function bootRequiresApprovalWhenChanges(): void
    {
        if (is_subclass_of(static::class, GatesCreation::class)) {
            static::addGlobalScope(new ApprovalDraftScope());
        }

        // Null, not true, when the save may go on: Eloquent stops at the first listener answering
        // anything, so true would silently skip every `saving` listener registered after this one.
        static::saving(function ($item) {
            try {
                return self::gateSave($item) ? null : false;
            } finally {
                // Covers this save only, whichever path it took.
                $item->forcedModification = false;
            }
        });

        // Written once the save went through: a veto or a database error must not leave an `applied`
        // row for a change that never happened.
        static::saved(fn ($item) => self::writePendingAudit($item));

        // Written as soon as the row is: a listener saving the model again would otherwise start
        // over and lose it, leaving a draft nobody can decide.
        static::created(fn ($item) => self::settleWrite($item));
        static::updated(fn ($item) => self::settleWrite($item));
    }

    private static function gateSave($item): bool
    {
        // Anything still here is from an attempt that never finished. A failed draft insert also
        // left its mark on the row, which a retry the gate lets through must not inherit.
        if ($item->pendingAudit && !$item->exists && $item instanceof GatesCreation) {
            unset($item->attributes[$item->approvalDraftColumn()]);
        }

        $item->pendingAudit = null;

        // A forced update covers this save only, whichever path it takes below.
        $forced = $item->isForcedApprovalUpdate();
        $item->setForcedApprovalUpdate(false);

        if (!$item->exists) {
            if (!$forced && $item instanceof GatesCreation && $item->requiresApprovalOnCreate($item->getDirty()) === true) {
                return self::captureCreation($item);
            }

            return true;
        }

        return self::withSettledOriginal($item, function () use ($item, $forced) {
            if (!$forced) {
                if ($item->modelApproval && call_user_func_array($item->modelApprovalFn, [$item->getDirty()]) === true) {
                    return self::captureSave($item);
                }

                if ($item->requiresApprovalWhen($item->getDirty()) === true) {
                    return self::captureSave($item);
                }
            }

            if ($item->saveModifications) {
                return self::captureModification($item);
            }

            return true;
        });
    }

    private static function settleWrite($item): void
    {
        $item->settledAttributes = ['before' => $item->getRawOriginal(), 'after' => $item->getAttributes()];

        self::writePendingAudit($item);
    }

    /**
     * Eloquent syncs the original values only after `saved`, so a listener saving the model again sees
     * what was just written as still dirty: it would be requested over again, and holding it would
     * undo it on the instance, down to the id of a row just created. A save from inside a listener
     * compares against what's really in the row.
     */
    private static function withSettledOriginal($item, callable $callback): bool
    {
        $settled = $item->settledAttributes;

        if (!$settled || $settled['before'] !== $item->getRawOriginal()) {
            return $callback();
        }

        $item->original = $settled['after'];

        try {
            return $callback();
        } finally {
            $item->original = $settled['before'];
        }
    }

    private static function writePendingAudit($item): void
    {
        if ($modification = $item->pendingAudit) {
            $item->pendingAudit = null;
            $item->modifications()->save($modification);
            $item->setLastModification($modification);
        }
    }

    /**
     * Approvers needed to apply a change. Override it to raise the number: the trait's property
     * can't be redeclared with another default on the class using the trait (PHP fatal error).
     */
    protected function approversRequired(): int
    {
        return $this->approversRequired;
    }

    /**
     * Disapprovers needed to reject a change. See approversRequired().
     */
    protected function disapproversRequired(): int
    {
        return $this->disapproversRequired;
    }

    /**
     * How a model implementing GatesCreation holds a creation until it's approved.
     * Override it to choose per model. Pending rows keep working if this changes, but
     * a model leaving draft mode stops hiding its rows, so approve those first.
     */
    public function approvalCreationMode(): string
    {
        return config('approvals.creation.mode', GatesCreation::DEFERRED);
    }

    /**
     * The boolean column marking a row as waiting for approval in draft mode.
     */
    public function approvalDraftColumn(): string
    {
        return config('approvals.creation.draft_column', 'approval_pending');
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
     * Changed keys not covered by any group stay together as a single decision
     * in one more item, keyed ModificationItem::KEY_UNGROUPED.
     */
    protected function approvableItemGroups(array $dirtyKeys): array
    {
        return [];
    }

    public function modifications(): MorphMany
    {
        $modificationModel = ResolvesApprovalModels::modificationClass();
        $instance = new $modificationModel();
        $table = $instance->getTable();

        // Not morphMany(): that would look for them on this model's connection, while they're saved
        // on the Modification's own.
        return $this->newMorphMany(
            $instance->newQuery(),
            $this,
            $table . '.modifiable_type',
            $table . '.modifiable_id',
            $this->getKeyName()
        );
    }

    public static function creations()
    {
        $modificationClass = ResolvesApprovalModels::modificationClass();

        return $modificationClass::whereModifiableType((new static())->getMorphClass())->whereIsUpdate(false)->get();
    }

    /**
     * Creations of this model still waiting for a decision. In deferred mode this is the only way
     * to reach them, since there's no model yet to ask for its modifications().
     */
    public static function pendingCreations()
    {
        $modificationClass = ResolvesApprovalModels::modificationClass();

        return $modificationClass::whereModifiableType((new static())->getMorphClass())
            ->whereIsUpdate(false)
            ->where('flow', Modification::FLOW_APPROVAL)
            ->where('status', Modification::STATUS_PENDING)
            ->get();
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
        if ($modification->isCreation()) {
            $this->applyCreationDecision($modification, $approved);

            return;
        }

        if ($approved) {
            if ($this->updateWhenApproved) {
                self::applyLegacyDiff($this, $modification->modifications->toArray());

                // A listener vetoed the save: nothing was written, so the change stays pending.
                if (!$this->saveApprovedChanges()) {
                    return;
                }
            }

            $modification->items()->pending()->update(['status' => $this->updateWhenApproved ? 'applied' : 'approved']);

            if ($this->deleteWhenApproved) {
                $modification->delete();
            } else {
                $modification->status = Modification::STATUS_APPROVED;
                $modification->save();
            }
        } else {
            $modification->items()->pending()->update(['status' => 'rejected']);

            if ($this->deleteWhenDisapproved) {
                $modification->delete();
            } else {
                $modification->status = Modification::STATUS_REJECTED;
                $modification->save();
            }
        }
    }

    /**
     * Each pending row is decided by its own shape, not by the current mode: one with a modifiable
     * is a draft row, already inserted; one without is a deferred creation, inserted only now.
     */
    private function applyCreationDecision(Modification $modification, bool $approved): void
    {
        if ($approved) {
            if ($this->exists) {
                $this->setAttribute($this->approvalDraftColumn(), false);
            } else {
                self::applyLegacyDiff($this, $modification->modifications->toArray());
                self::dateFromRequest($this, $modification);
            }

            if (!$this->saveApprovedChanges()) {
                return;
            }

            $modification->modifiable()->associate($this);
        } elseif ($this->exists && $this->delete() === false) {
            return;
        }

        if ($approved ? $this->deleteWhenApproved : $this->deleteWhenDisapproved) {
            $modification->delete();
        } else {
            $modification->status = $approved ? Modification::STATUS_APPROVED : Modification::STATUS_REJECTED;
            $modification->save();
        }
    }

    /**
     * The row dates from its request, as a draft's does, not from whenever someone got to it.
     * A created_at set by hand is already in the diff, and stays.
     */
    private static function dateFromRequest($model, Modification $modification): void
    {
        $column = $model->usesTimestamps() ? $model->getCreatedAtColumn() : null;

        if ($column && $modification->occurred_at && !$model->isDirty($column)) {
            $model->setCreatedAt($modification->occurred_at);
        }
    }

    /**
     * Applies (or rejects) a single ModificationItem's proposed values,
     * touching only the fields captured in that item.
     */
    public function applyModificationItemChanges(ModificationItem $item, bool $approved): void
    {
        if ($approved) {
            if ($this->updateWhenApproved) {
                self::applyLegacyDiff($this, self::itemDiffToLegacyShape($item));

                if (!$this->saveApprovedChanges()) {
                    return;
                }
            }

            $item->status = $this->updateWhenApproved ? 'applied' : 'approved';
            $item->save();
        } else {
            $item->status = 'rejected';
            $item->save();
        }
    }

    /**
     * Saves past the gate without an audit row, then restores auditing so a later save on this
     * same instance is still recorded.
     */
    private function saveApprovedChanges(): bool
    {
        $saveModifications = $this->saveModifications;

        $this->setForcedApprovalUpdate(true);
        $this->noSaveModifications();

        try {
            $saved = $this->save();
        } finally {
            // An earlier listener vetoing the save never reached the hook that consumes this.
            $this->setForcedApprovalUpdate(false);
            $this->saveModifications = $saveModifications;
        }

        // What was refused must not reach the row with this instance's next save.
        if (!$saved) {
            self::forgetHeldChanges($this);
        }

        return $saved;
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

    /**
     * Make the next save produce a Modification even if nothing changed, to record or decide something
     * outside the columns: an applied audit row, or a pending request if the gate holds the save.
     */
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
                $originalKey => self::toJsonSafe($entry['original'] ?? null),
                $modifiedKey => self::toJsonSafe($entry['proposed'] ?? null),
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

    /**
     * Holds a new model back according to its creation mode. A creation is decided as a whole, so it
     * never gets items. Draft mode only marks the row: the Modification is written once it has an id.
     */
    private static function captureCreation($item): bool
    {
        $mode = $item->approvalCreationMode();

        if (!in_array($mode, [GatesCreation::DEFERRED, GatesCreation::DRAFT], true)) {
            throw new \InvalidArgumentException("Unknown approval creation mode [{$mode}].");
        }

        $diff = self::buildModificationDiff($item, forApproval: true);

        // The same creation is the same request, in whatever order its attributes were set.
        ksort($diff);

        $modificationModel = ResolvesApprovalModels::modificationClass();

        if ($mode === GatesCreation::DEFERRED) {
            $pending = $modificationModel::where('modifiable_type', $item->getMorphClass())
                ->whereNull('modifiable_id')
                ->where('flow', Modification::FLOW_APPROVAL)
                ->where('status', Modification::STATUS_PENDING)
                ->where('md5', md5(json_encode($diff)))
                ->first();

            if ($pending) {
                $item->setLastModification($pending);
                $item->modificationMeta = false;

                return false;
            }
        }

        $modification = new $modificationModel();

        self::fillModificationModel(
            item: $item,
            modification: $modification,
            diff: $diff,
            flow: Modification::FLOW_APPROVAL,
            status: Modification::STATUS_PENDING,
            approversRequired: $item->approversRequired(),
            disapproversRequired: $item->disapproversRequired(),
            operationId: $item->currentOperationId()
        );

        if ($mode === GatesCreation::DRAFT) {
            $item->setAttribute($item->approvalDraftColumn(), true);
            $item->pendingAudit = $modification;

            return true;
        }

        $modification->modifiable_type = $item->getMorphClass();
        $modification->save();
        $item->setLastModification($modification);

        return false;
    }

    public static function captureSave($item): bool
    {
        if ($item->getKey() === null) {
            throw new \LogicException('A change to a model loaded without its key can\'t be held for approval.');
        }

        // Nothing to decide (no changes, a touch(), only untracked columns): save it as usual
        // instead of blocking it behind a pending row that could never write anything. Unless the
        // caller forced the request: what's being decided lives outside the columns, such as a photo.
        if (!$item->forcedModification && array_diff(array_keys($item->getDirty()), self::untrackedKeys($item)) === []) {
            return true;
        }

        $diff = self::buildModificationDiff($item, forApproval: true);

        // Requests for one row wait for each other, or the same change sent twice at once would
        // find nothing pending both times and be held twice.
        $item->getConnection()->transaction(function () use ($item, $diff) {
            $item->newModelQuery()->whereKey($item->getKey())->lockForUpdate()->first();

            self::holdChange($item, $diff);
        });

        self::forgetHeldChanges($item);

        return false;
    }

    /**
     * The held row itself, or the one already waiting with the same change.
     */
    private static function holdChange($item, array $diff): void
    {

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
            $item->modificationMeta = false;

            return;
        }

        $modificationModel = ResolvesApprovalModels::modificationClass();
        $modification = new $modificationModel();

        self::fillModificationModel(
            item: $item,
            modification: $modification,
            diff: $diff,
            flow: Modification::FLOW_APPROVAL,
            status: Modification::STATUS_PENDING,
            approversRequired: $item->approversRequired(),
            disapproversRequired: $item->disapproversRequired(),
            operationId: $item->currentOperationId()
        );

        $item->modifications()->save($modification);

        $groups = self::resolveApprovableGroups($item, $diff);

        if ($groups) {
            self::createItemsForGroups($modification, $groups, $item);
        }

        $item->setLastModification($modification);
    }

    /**
     * A held change lives in its Modification, not on the model. Left dirty on the instance, the next
     * save would carry it along: into a later request, which could apply it after this one was
     * rejected, or straight to the column if that save isn't gated.
     */
    private static function forgetHeldChanges($item): void
    {
        $item->setRawAttributes($item->getRawOriginal());
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

            $item->pendingAudit = $modification;
        }

        return true;
    }

    /**
     * Columns that can't hold a change back on their own: those the model opted out of tracking,
     * and the timestamp Eloquent maintains (a touch() is not a change to approve).
     */
    private static function untrackedKeys($item): array
    {
        return array_merge($item->noTrackModification ?? [], self::maintainedTimestampKeys($item));
    }

    private static function maintainedTimestampKeys($item): array
    {
        return $item->usesTimestamps() && $item->getUpdatedAtColumn() ? [$item->getUpdatedAtColumn()] : [];
    }

    /**
     * Splits the field groups declared by approvableItemGroups() into the
     * ones that actually changed in this diff, keyed by group name.
     */
    private static function resolveApprovableGroups($item, array $diff): array
    {
        // A full save lists untouched columns too, and those are nothing to decide.
        $changed = array_values(array_filter(array_keys($diff), fn ($key) => $item->isDirty($key)));

        $groups = $item->approvableItemGroups($changed);

        $resolved = collect($groups)
            ->map(fn ($fields) => array_values(array_intersect($fields, $changed)))
            ->filter(fn ($fields) => count($fields) > 0)
            ->all();

        if ($resolved === []) {
            return [];
        }

        // A Modification with items is decided only through them, so a field outside every group
        // needs its own item or approving would mark it approved without ever writing it.
        $ungrouped = array_values(array_diff($changed, array_merge(...array_values($resolved))));

        if ($ungrouped !== []) {
            $resolved[ModificationItem::KEY_UNGROUPED] = $ungrouped;
        }

        return $resolved;
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
                    'approvers_required' => $item->approversRequired(),
                    'disapprovers_required' => $item->disapproversRequired(),
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
        $originalKey = self::originalDiffKey();
        $modifiedKey = self::modifiedDiffKey();
        $storedKey = self::storedDiffKey();

        foreach ($diff as $key => $mod) {
            if (array_key_exists($storedKey, $mod)) {
                self::writeStoredValue($model, $key, self::fromJsonSafe($mod[$storedKey]));

                continue;
            }

            // Unchanged on both sides, as every untouched column of a full save: writing it back
            // would undo whatever changed the column since the request was made.
            if ($model->exists && ($mod[$originalKey] ?? null) === $mod[$modifiedKey]) {
                continue;
            }

            $proposed = $mod[$modifiedKey];

            // Already the stored form: going through the setter would mutate or encrypt it twice.
            if (!is_array($proposed) && self::keepsStoredValue($model, $key)) {
                self::writeStoredValue($model, $key, $proposed);

                continue;
            }

            // A row about to be inserted has nothing to merge into.
            $current = $model->exists ? $model->getAttribute($key) : null;

            // Only a map of sub-keys can be merged: a date cast also has `set()` (Carbon's), but its
            // proposed value is a string or null, and a Fluent holds its whole value.
            if (is_array($proposed) && is_object($current) && method_exists($current, 'set') && !$current instanceof Fluent) {
                foreach ($proposed as $k => $v) {
                    $current->set($k, $v);
                }
            } else {
                $model->setAttribute($key, $proposed);
            }
        }
    }

    private static function writeStoredValue($model, string $key, $value): void
    {
        $model->attributes[$key] = $value;
        unset($model->classCastCache[$key], $model->attributeCastCache[$key]);
    }

    /**
     * Attributes whose value in PHP isn't what gets stored, or can't survive the JSON diff: the diff
     * keeps their stored form. Writing an accessor's output back corrupts the column, a mutator would
     * run twice, a value object comes back as an array, an empty object as an empty array, and an
     * encrypted value would sit in plain text.
     * Array-like values are excluded by the callers, since those are stored whole or merged.
     */
    private static function keepsStoredValue($model, string $key): bool
    {
        $cast = strtolower((string) ($model->getCasts()[$key] ?? ''));

        return self::isEncryptedCast($cast)
            || in_array($cast, ['timestamp', 'object'], true)
            || self::hasAccessorOrMutator($model, $key)
            || $model->isEnumCastable($key)
            || $model->isClassCastable($key);
    }

    private static function hasAccessorOrMutator($model, string $key): bool
    {
        return $model->hasGetMutator($key)
            || $model->hasSetMutator($key)
            || $model->hasAttributeMutator($key)
            || $model->hasAttributeSetMutator($key);
    }

    /**
     * An enum inside a collection, as Eloquent stores it. A non-backed one can't be JSON-encoded at all.
     */
    private static function withoutEnums($value)
    {
        if (is_array($value)) {
            return array_map(fn ($entry) => self::withoutEnums($entry), $value);
        }

        return $value instanceof \UnitEnum ? ($value->value ?? $value->name) : $value;
    }

    /**
     * A plain value for something a column accepts but JSON wouldn't carry faithfully.
     */
    private static function scalarForDiff($item, $value)
    {
        if ($value instanceof Expression) {
            return $value->getValue($item->getConnection()->getQueryGrammar());
        }

        if ($value instanceof \UnitEnum) {
            return self::withoutEnums($value);
        }

        return $value instanceof \Stringable && !$value instanceof \DateTimeInterface ? (string) $value : $value;
    }

    private static function isEncryptedCast(string $cast): bool
    {
        return str_contains($cast, 'encrypted');
    }

    /**
     * Bytes that aren't valid UTF-8 (a binary column) can't be stored in the JSON diff as they are.
     * Postgres hands a `bytea` column back as a stream, left rewound for whoever reads it next.
     */
    private static function toJsonSafe($value)
    {
        if (is_resource($value)) {
            rewind($value);
            $bytes = stream_get_contents($value);
            rewind($value);

            $value = $bytes;
        }

        return is_string($value) && preg_match('//u', $value) !== 1
            ? [self::$binaryDiffKey => base64_encode($value)]
            : $value;
    }

    /**
     * Bytes go back as a stream, the one form every engine takes: Postgres refuses them as a string.
     */
    private static function fromJsonSafe($value)
    {
        if (!is_array($value) || array_keys($value) !== [self::$binaryDiffKey]) {
            return $value;
        }

        $stream = fopen('php://memory', 'r+');
        fwrite($stream, base64_decode($value[self::$binaryDiffKey]));
        rewind($stream);

        return $stream;
    }

    private static function itemDiffToLegacyShape(ModificationItem $item): array
    {
        $originalKey = self::originalDiffKey();
        $modifiedKey = self::modifiedDiffKey();
        $storedKey = self::storedDiffKey();
        $parentDiff = $item->modification?->modifications?->toArray() ?? [];

        $diff = [];

        foreach ($item->proposed->toArray() as $key => $value) {
            $diff[$key] = [
                // Not `$item->original`: inside a model that resolves to Eloquent's protected $original.
                $originalKey => $item->getAttribute('original')[$key] ?? null,
                $modifiedKey => $value,
            ];

            if (array_key_exists($storedKey, $parentDiff[$key] ?? [])) {
                $diff[$key][$storedKey] = $parentDiff[$key][$storedKey];
            }
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

    /**
     * A diff that will be written back (forApproval) also holds what a save would have stored, when
     * `mod` isn't exactly that: `mod` is for reading, and casting it back could round a decimal, drop
     * the time of a date or run a mutator again. Those columns also include untracked ones changed
     * alongside tracked ones, or approving would lose them.
     */
    private static function buildModificationDiff($item, bool $forApproval = false): array
    {
        // Reads the value back through a copy of the model, so casts and accessors that look at other
        // attributes keep seeing them, and a subclass or an abstract base resolve to the real class.
        $getReal = function ($key, $val) use ($item) {
            $setter = $item->newInstance();
            $setter->setRawAttributes($item->getAttributes());
            $setter->setAttribute($key, $val);

            $reader = $item->newInstance();
            $reader->setRawAttributes($setter->getAttributes());

            return $reader->getAttribute($key);
        };

        $reject = $forApproval ? [] : ($item->noTrackModification ?? []);

        $originalKey = self::originalDiffKey();
        $modifiedKey = self::modifiedDiffKey();

        // Something that can be read as a whole array. Other ArrayAccess objects (a Stringable) can't.
        $isStructure = fn ($value): bool => is_array($value)
            || ($value instanceof \ArrayAccess && method_exists($value, 'toArray'));

        // An array/ArrayAccess attribute replaced by a scalar or null (e.g. clearing a JSON column)
        // can't be diffed key by key: it's recorded as a whole replacement.
        $replacesStructure = fn ($original, $modified): bool => $isStructure($original) && ! $isStructure($modified);

        // Arrays and collections are written back whole, so that's what the diff keeps: positions or
        // sub-keys alone can't say what was removed, and approving them would replace the column with
        // just that fragment. Only an object exposing `set()` (a schemaless attribute) can be merged,
        // and a Fluent, which has `set()` too, is a plain value that must be able to lose keys.
        $isWholeValue = fn ($value): bool => is_array($value)
            || ($isStructure($value) && ($value instanceof Fluent || ! method_exists($value, 'set') || ! is_callable([$value, 'where'])));

        $isMergeable = fn ($value): bool => $isStructure($value) && ! $isWholeValue($value);

        // What a save would drop, which merging key by key can't do.
        $removedKeys = fn ($before, $after): array => array_keys(array_diff_key($before->toArray(), $after->toArray()));

        // Top-level keys to merge. Compared exactly: whatever changed inside one, even a nested
        // removal, is written by replacing that key.
        $changedKeys = function ($before, $after): array {
            $before = $before->toArray();

            return array_keys(array_filter(
                $after->toArray(),
                fn ($value, $key) => ! array_key_exists($key, $before) || $before[$key] !== $value,
                ARRAY_FILTER_USE_BOTH
            ));
        };

        $plain = fn ($value) => self::withoutEnums($value instanceof \ArrayAccess ? $value->toArray() : $value);

        // Both sides of a row go through this, so one value reads the same before and after a change.
        $readable = function ($key, $value) use ($item, $isStructure, $plain) {
            if ($value instanceof \DateTimeInterface) {
                return self::serializeDiffDate($item, $key, $value);
            }

            return self::toJsonSafe($isStructure($value) ? $plain($value) : self::scalarForDiff($item, $value));
        };

        $storesRaw = function ($key) use ($item, $isStructure, $isMergeable): bool {
            if (self::isEncryptedCast(strtolower((string) ($item->getCasts()[$key] ?? '')))) {
                return true;
            }

            // A value that can't even be read (an enum case since removed, an accessor needing a column
            // that wasn't selected) can still be saved, and must not stop that save.
            try {
                $original = $item->getOriginal($key);
                $current = $item->getAttribute($key);
            } catch (\Throwable) {
                return true;
            }

            if ($isMergeable($original) || $isMergeable($current)) {
                return false;
            }

            // Its getter's output may not even be something its setter accepts.
            if (self::hasAccessorOrMutator($item, $key)) {
                return true;
            }

            return self::keepsStoredValue($item, $key) && ! $isStructure($original) && ! $isStructure($current);
        };

        $storedValue = function ($key) use ($item, $forApproval) {
            $stored = $item->getAttributes()[$key] ?? null;

            if ($forApproval && $stored instanceof Expression) {
                throw new \LogicException("[{$key}] is a raw SQL expression, which can't be held for approval.");
            }

            return self::toJsonSafe($stored instanceof \DateTimeInterface ? $item->fromDateTime($stored) : self::scalarForDiff($item, $stored));
        };

        $buildRow = function ($key) use ($item, $getReal, $changedKeys, $originalKey, $modifiedKey, $replacesStructure, $isWholeValue, $isStructure, $plain, $readable, $storesRaw, $storedValue, $removedKeys) {
            if ($storesRaw($key)) {
                return [
                    $originalKey => self::toJsonSafe($item->getRawOriginal($key)),
                    $modifiedKey => $storedValue($key),
                ];
            }

            $original = $item->getOriginal($key);
            $modified = $getReal($key, $item->getAttribute($key));

            if ($replacesStructure($original, $modified)) {
                return [
                    $originalKey => $plain($original),
                    $modifiedKey => $modified,
                ];
            }

            if ($isWholeValue($original)) {
                return [
                    $originalKey => $plain($original),
                    $modifiedKey => $plain($modified),
                ];
            }

            if ($isStructure($original)) {
                $changed = $changedKeys($original, $modified);
                $removed = $removedKeys($original, $modified);

                return [
                    $originalKey => $original->where(fn ($v, $k) => in_array($k, [...$changed, ...$removed]))->toArray(),
                    $modifiedKey => $modified->where(fn ($v, $k) => in_array($k, $changed))->toArray(),
                ];
            }

            return [
                $originalKey => $readable($key, $original),
                $modifiedKey => $readable($key, $item->getAttribute($key)),
            ];
        };

        $addStored = function (array $row, $key) use ($item, $forApproval, $isMergeable, $getReal, $removedKeys, $changedKeys, $storedValue, $storesRaw, $originalKey, $modifiedKey) {
            if (! $forApproval) {
                return $row;
            }

            // A merge writes only what changed, leaving alone what changed since the request, unless
            // it has to drop a key or only the order changed: only writing the whole value can do those.
            if (! $storesRaw($key) && $item->exists && $isMergeable($original = $item->getOriginal($key))) {
                $current = $getReal($key, $item->getAttribute($key));

                if (! $isMergeable($current) || ($removedKeys($original, $current) === [] && $changedKeys($original, $current) !== [])) {
                    return $row;
                }

                $row[self::storedDiffKey()] = $storedValue($key);

                return $row;
            }

            $stored = $storedValue($key);

            if ($stored !== $row[$modifiedKey] || $row[$originalKey] === $row[$modifiedKey] || is_array($stored)) {
                $row[self::storedDiffKey()] = $stored;
            }

            return $row;
        };

        $isNoopChange = function ($key) use ($item, $getReal, $replacesStructure, $isWholeValue, $isStructure, $plain, $storesRaw) {
            // Eloquent already compared the stored forms to call it dirty. A column that wasn't selected
            // has no original to compare with: setting it to null is a change we can't rule out.
            if ($storesRaw($key) || ! array_key_exists($key, $item->getRawOriginal())) {
                return false;
            }

            $original = $item->getOriginal($key);

            if ($replacesStructure($original, $getReal($key, $item->getAttribute($key)))) {
                return false;
            }

            if ($isWholeValue($original)) {
                // Exact, since the differ ignores tiny float changes and key order, which Eloquent
                // does write: approving must reach the same result as saving directly.
                return $plain($original) === $plain($getReal($key, $item->getAttribute($key)));
            }

            if ($isStructure($original)) {
                $current = $getReal($key, $item->getAttribute($key));

                return $original->toArray() === $current->toArray();
            }

            return $original === $getReal($key, $item->getAttribute($key));
        };

        if ($item->fullSaveModification) {
            if ($item->onlyInUpdate && ! $item->getDirty()) {
                return [];
            }

            // Every column on record, plus any that changed without having been selected. Untouched
            // ones hold the same value on both sides, which is how approving knows to leave them alone.
            return collect(array_keys($item->getRawOriginal() + $item->getDirty()))
                ->reject(fn ($key) => in_array($key, $reject))
                ->mapWithKeys(function ($key) use ($item, $buildRow, $addStored, $originalKey, $modifiedKey) {
                    $row = $buildRow($key);

                    if (! $item->isDirty($key)) {
                        $row[$modifiedKey] = $row[$originalKey];

                        return [$key => $row];
                    }

                    return [$key => $addStored($row, $key)];
                })
                ->all();
        }

        // On its own, the timestamp is a touch(). Set alongside other changes, it was set on purpose.
        if ($item->exists && array_keys($item->getDirty()) === self::maintainedTimestampKeys($item)) {
            return [];
        }

        // A model deleted and saved again has nothing dirty, yet inserts all its attributes.
        return collect($item->exists ? $item->getDirty() : $item->getAttributes())
            ->reject(fn ($change, $key) => in_array($key, $reject))
            ->reject(fn ($change, $key) => $item->exists && $isNoopChange($key))
            ->map(fn ($change, $key) => $addStored($buildRow($key), $key))
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
        $modification->occurred_at = $occurredAt ?? $item->freshTimestamp();

        if ($operationId) {
            $modification->operation_id = $operationId;
        }

        if ($eventType) {
            $modification->event_type = $eventType;
        }

        if ($context !== null) {
            $modification->context = $context;
        }

        if ($modifier) {
            $modification->modifier_id = $modifier->getKey();
            $modification->modifier_type = $modifier->getMorphClass();
        }

        // A manual event on a deleted model isn't a creation.
        if (!$item->exists && ($eventType === null || $item->getKey() === null)) {
            $modification->is_update = false;
        }

        if ($item->modificationMeta) {
            // The cast only reads arrays back, and the meta belongs to this Modification alone.
            $modification->meta = is_scalar($item->modificationMeta) ? [$item->modificationMeta] : $item->modificationMeta;
            $item->modificationMeta = false;
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

    /**
     * Key holding what a save would have stored, on rows where `mod` isn't exactly that.
     * Configurable via `approvals.diff_keys.stored` (defaults to 'raw').
     */
    private static function storedDiffKey(): string
    {
        return config('approvals.diff_keys.stored', 'raw');
    }
}
