<?php

declare(strict_types=1);

namespace ReynoTECH\Approvals\Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use ReynoTECH\Approvals\Models\Modification;
use ReynoTECH\Approvals\Models\ModificationItem;
use ReynoTECH\Approvals\Tests\Fixtures\ApproverModel;
use ReynoTECH\Approvals\Tests\Fixtures\CastedApprovableModel;
use ReynoTECH\Approvals\Tests\TestCase;

final class DateAttributeApprovalTest extends TestCase
{
    /** @return array<string, array{string, string, string, string}> */
    public static function dateColumns(): array
    {
        return [
            'datetime' => ['starts_at', '2026-01-01 10:00:00', '2026-02-02 11:30:00', '2026-02-02 11:30:00'],
            'immutable_datetime' => ['starts_immutable', '2026-01-01 10:00:00', '2026-02-02 11:30:00', '2026-02-02 11:30:00'],
            'date' => ['starts_on', '2026-01-01', '2026-02-02', '2026-02-02 00:00:00'],
        ];
    }

    #[DataProvider('dateColumns')]
    public function test_approving_a_change_writes_the_date(string $column, string $from, string $to, string $stored): void
    {
        $model = $this->gatedModel([$column => $from]);

        $model->{$column} = $to;
        $model->save();

        $this->assertSame($from, substr((string) $model->fresh()->getRawOriginal($column), 0, strlen($from)), 'nothing changes until it is approved');

        $this->approve();

        $this->assertSame($stored, $model->fresh()->getRawOriginal($column));
    }

    public function test_approving_a_cleared_date_writes_null(): void
    {
        $model = $this->gatedModel(['starts_at' => '2026-01-01 10:00:00']);

        $model->starts_at = null;
        $model->save();

        $this->approve();

        $this->assertNull($model->fresh()->getRawOriginal('starts_at'));
    }

    public function test_approving_an_item_writes_the_date(): void
    {
        $model = $this->gatedModel(['starts_at' => '2026-01-01 10:00:00']);
        $model->itemGroups = ['schedule' => ['starts_at']];

        $model->starts_at = '2026-02-02 11:30:00';
        $model->save();

        ApproverModel::create(['name' => 'Approver'])->approveItem(ModificationItem::query()->firstOrFail());

        $this->assertSame('2026-02-02 11:30:00', $model->fresh()->getRawOriginal('starts_at'));
    }

    /**
     * Laravel's HandleExceptions turns warnings into ErrorException: a warning while applying
     * would fail the request with the vote already saved and the change still pending.
     */
    public function test_approving_a_date_raises_no_warning(): void
    {
        $model = $this->gatedModel(['starts_at' => '2026-01-01 10:00:00']);

        $model->starts_at = '2026-02-02 11:30:00';
        $model->save();

        set_error_handler(static function (int $level, string $message, string $file, int $line): bool {
            throw new \ErrorException($message, 0, $level, $file, $line);
        });

        try {
            $this->approve();
        } finally {
            restore_error_handler();
        }

        $this->assertSame(Modification::STATUS_APPROVED, Modification::query()->firstOrFail()->status);
    }

    public function test_an_object_exposing_set_is_still_merged_key_by_key(): void
    {
        $model = $this->gatedModel(['extra' => ['pressure' => 100, 'state' => 'ok']]);

        $model->extra->set('state', 'bad');
        $model->save();

        $this->assertSame(
            ['extra' => ['ori' => ['state' => 'ok'], 'mod' => ['state' => 'bad']]],
            Modification::query()->firstOrFail()->modifications->toArray(),
        );

        $this->approve();

        $this->assertSame(['pressure' => 100, 'state' => 'bad'], json_decode($model->fresh()->getRawOriginal('extra'), true));
    }

    /** @param array<string, mixed> $attributes */
    private function gatedModel(array $attributes): CastedApprovableModel
    {
        $model = CastedApprovableModel::query()->findOrFail(CastedApprovableModel::create($attributes)->id);
        $model->gateChanges = true;

        return $model;
    }

    private function approve(): void
    {
        ApproverModel::create(['name' => 'Approver'])->approve(Modification::query()->latest('id')->firstOrFail());
    }
}
