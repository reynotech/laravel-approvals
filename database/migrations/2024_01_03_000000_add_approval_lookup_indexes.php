<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('modifications')) {
            return;
        }

        $needsModifiableIndex = ! Schema::hasIndex('modifications', ['modifiable_type', 'modifiable_id']);
        $needsModifierIndex = ! Schema::hasIndex('modifications', ['modifier_type', 'modifier_id']);
        $needsPendingLookupIndex = ! Schema::hasIndex(
            'modifications',
            ['modifiable_type', 'is_modification', 'modifiable_id', 'id'],
        );

        if (! $needsModifiableIndex && ! $needsModifierIndex && ! $needsPendingLookupIndex) {
            return;
        }

        Schema::table('modifications', function (Blueprint $table) use ($needsModifiableIndex, $needsModifierIndex, $needsPendingLookupIndex): void {
            if ($needsModifiableIndex) {
                $table->index(['modifiable_type', 'modifiable_id'], 'approvals_modifiable_lookup_idx');
            }
            if ($needsModifierIndex) {
                $table->index(['modifier_type', 'modifier_id'], 'approvals_modifier_lookup_idx');
            }
            if ($needsPendingLookupIndex) {
                $table->index(
                    ['modifiable_type', 'is_modification', 'modifiable_id', 'id'],
                    'approvals_pending_lookup_idx',
                );
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('modifications')) {
            return;
        }

        $indexes = array_values(array_filter([
            'approvals_modifiable_lookup_idx',
            'approvals_modifier_lookup_idx',
            'approvals_pending_lookup_idx',
        ], static fn (string $index): bool => Schema::hasIndex('modifications', $index)));

        if ($indexes === []) {
            return;
        }

        Schema::table('modifications', function (Blueprint $table) use ($indexes): void {
            foreach ($indexes as $index) {
                $table->dropIndex($index);
            }
        });
    }
};
