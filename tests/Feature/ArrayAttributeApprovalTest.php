<?php

declare(strict_types=1);

namespace ReynoTECH\Approvals\Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use ReynoTECH\Approvals\Models\Modification;
use ReynoTECH\Approvals\Models\ModificationItem;
use ReynoTECH\Approvals\Tests\Fixtures\ApproverModel;
use ReynoTECH\Approvals\Tests\Fixtures\CastedApprovableModel;
use ReynoTECH\Approvals\Tests\TestCase;

final class ArrayAttributeApprovalTest extends TestCase
{
    private const COLUMNS = ['as_array', 'as_collection', 'as_collection_class', 'as_array_object'];

    private const CHANGES = [
        'list: append' => [['a', 'b', 'c'], ['a', 'b', 'c', 'd']],
        'list: remove last' => [['a', 'b', 'c'], ['a', 'b']],
        'list: remove first' => [['a', 'b', 'c'], ['b', 'c']],
        'list: replace middle' => [['a', 'b', 'c'], ['a', 'x', 'c']],
        'list: reorder' => [['a', 'b', 'c'], ['c', 'b', 'a']],
        'list: empty it' => [['a', 'b'], []],
        'map: edit a key' => [['x' => 1, 'y' => 2], ['x' => 1, 'y' => 3]],
        'map: add a key' => [['x' => 1], ['x' => 1, 'y' => 2]],
        'map: remove a key' => [['x' => 1, 'y' => 2], ['x' => 1]],
        'map: nested edit' => [['x' => ['p' => 1, 'q' => 2], 'y' => 3], ['x' => ['p' => 1, 'q' => 9], 'y' => 3]],
    ];

    /** @return iterable<string, array{string, array<mixed>, array<mixed>}> */
    public static function changes(): iterable
    {
        foreach (self::COLUMNS as $column) {
            foreach (self::CHANGES as $name => [$from, $to]) {
                yield "{$column} {$name}" => [$column, $from, $to];
            }
        }
    }

    /**
     * @param  array<mixed>  $from
     * @param  array<mixed>  $to
     */
    #[DataProvider('changes')]
    public function test_a_gated_change_stores_the_whole_value_and_applies_it(string $column, array $from, array $to): void
    {
        $model = $this->gatedModel([$column => $from]);

        $model->{$column} = $to;
        $model->save();

        $this->assertSame($from, $this->stored($model, $column), 'nothing changes until it is approved');
        $this->assertSame(
            [$column => ['ori' => $from, 'mod' => $to]],
            Modification::query()->firstOrFail()->modifications->toArray(),
        );

        ApproverModel::create(['name' => 'Approver'])->approve(Modification::query()->firstOrFail());

        $this->assertSame($to, $this->stored($model, $column));
    }

    /**
     * @param  array<mixed>  $from
     * @param  array<mixed>  $to
     */
    #[DataProvider('changes')]
    public function test_a_direct_change_is_audited_with_the_whole_value(string $column, array $from, array $to): void
    {
        $model = CastedApprovableModel::query()->findOrFail(CastedApprovableModel::create([$column => $from])->id);

        $model->{$column} = $to;
        $model->save();

        $this->assertSame($to, $this->stored($model, $column));
        $this->assertSame(
            [$column => ['ori' => $from, 'mod' => $to]],
            Modification::query()->firstOrFail()->modifications->toArray(),
        );
    }

    public function test_approving_an_item_writes_the_whole_value(): void
    {
        $model = $this->gatedModel(['as_array' => ['a', 'b', 'c']]);
        $model->itemGroups = ['tags' => ['as_array']];

        $model->as_array = ['a', 'b'];
        $model->save();

        $item = ModificationItem::query()->firstOrFail();
        $this->assertSame(['as_array' => ['a', 'b', 'c']], $item->original->toArray());
        $this->assertSame(['as_array' => ['a', 'b']], $item->proposed->toArray());

        ApproverModel::create(['name' => 'Approver'])->approveItem($item);

        $this->assertSame(['a', 'b'], $this->stored($model, 'as_array'));
    }

    public function test_two_different_removals_stay_separate_requests(): void
    {
        $model = $this->gatedModel(['as_array' => ['a', 'b', 'c']]);
        $model->as_array = ['a', 'b'];
        $model->save();

        $other = CastedApprovableModel::query()->findOrFail($model->id);
        $other->gateChanges = true;
        $other->as_array = ['a'];
        $other->save();

        $this->assertSame(2, Modification::query()->count());
    }

    public function test_resubmitting_the_same_removal_keeps_one_request(): void
    {
        $model = $this->gatedModel(['as_array' => ['a', 'b', 'c']]);
        $model->as_array = ['a', 'b'];
        $model->save();

        $again = CastedApprovableModel::query()->findOrFail($model->id);
        $again->gateChanges = true;
        $again->as_array = ['a', 'b'];
        $again->save();

        $this->assertSame(1, Modification::query()->count());
    }

    public function test_saving_an_equal_value_records_nothing(): void
    {
        $model = CastedApprovableModel::query()->findOrFail(CastedApprovableModel::create(['as_array' => ['a', 'b']])->id);

        $model->as_array = ['a', 'b'];
        $model->save();

        $this->assertSame(0, Modification::query()->count());
    }

    public function test_a_full_save_records_the_whole_value(): void
    {
        $model = CastedApprovableModel::query()->findOrFail(CastedApprovableModel::create(['as_array' => ['a', 'b', 'c']])->id);

        $model->withFullSaveModification();
        $model->as_array = ['a', 'b'];
        $model->save();

        $this->assertSame(
            ['ori' => ['a', 'b', 'c'], 'mod' => ['a', 'b']],
            Modification::query()->firstOrFail()->modifications['as_array'],
        );
    }

    /** @param array<string, mixed> $attributes */
    private function gatedModel(array $attributes): CastedApprovableModel
    {
        $model = CastedApprovableModel::query()->findOrFail(CastedApprovableModel::create($attributes)->id);
        $model->gateChanges = true;

        return $model;
    }

    /** @return array<mixed> */
    private function stored(CastedApprovableModel $model, string $column): array
    {
        return json_decode((string) $model->fresh()->getRawOriginal($column), true, flags: JSON_THROW_ON_ERROR);
    }
}
