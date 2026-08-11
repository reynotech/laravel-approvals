<?php

declare(strict_types=1);

namespace ReynoTECH\Approvals\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use ReynoTECH\Approvals\Tests\TestCase;

class ApprovalLookupIndexesMigrationTest extends TestCase
{
    public function test_it_adds_lookup_indexes_without_duplicating_fresh_morph_indexes(): void
    {
        $this->assertTrue(Schema::hasIndex('modifications', ['modifiable_type', 'modifiable_id']));
        $this->assertTrue(Schema::hasIndex('modifications', ['modifier_type', 'modifier_id']));
        $this->assertTrue(Schema::hasIndex(
            'modifications',
            ['modifiable_type', 'is_modification', 'modifiable_id', 'id'],
        ));

        $this->assertFalse(Schema::hasIndex('modifications', 'approvals_modifiable_lookup_idx'));
        $this->assertFalse(Schema::hasIndex('modifications', 'approvals_modifier_lookup_idx'));
    }

    public function test_it_repairs_legacy_tables_idempotently(): void
    {
        Schema::table('modifications', function (Blueprint $table): void {
            $table->dropIndex('modifications_modifiable_type_modifiable_id_index');
            $table->dropIndex('modifications_modifier_type_modifier_id_index');
            $table->dropIndex('approvals_pending_lookup_idx');
        });

        $migration = require __DIR__ . '/../../database/migrations/2024_01_03_000000_add_approval_lookup_indexes.php';
        $migration->up();
        $migration->up();

        $this->assertTrue(Schema::hasIndex('modifications', ['modifiable_type', 'modifiable_id']));
        $this->assertTrue(Schema::hasIndex('modifications', ['modifier_type', 'modifier_id']));
        $this->assertTrue(Schema::hasIndex(
            'modifications',
            ['modifiable_type', 'is_modification', 'modifiable_id', 'id'],
        ));
    }

    public function test_rollback_preserves_indexes_owned_by_the_create_migration(): void
    {
        $migration = require __DIR__ . '/../../database/migrations/2024_01_03_000000_add_approval_lookup_indexes.php';

        $migration->down();

        $this->assertTrue(Schema::hasIndex('modifications', ['modifiable_type', 'modifiable_id']));
        $this->assertTrue(Schema::hasIndex('modifications', ['modifier_type', 'modifier_id']));
        $this->assertFalse(Schema::hasIndex(
            'modifications',
            ['modifiable_type', 'is_modification', 'modifiable_id', 'id'],
        ));
    }
}
