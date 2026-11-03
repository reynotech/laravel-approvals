<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('modifications', function (Blueprint $table) {
            $table->string('flow')->nullable()->after('is_modification');
            $table->string('status')->nullable()->after('flow');
            $table->string('event_type')->nullable()->after('status');
            $table->string('operation_id')->nullable()->after('event_type');
            $table->json('context')->nullable()->after('operation_id');
            $table->timestamp('occurred_at')->nullable()->after('context');
            $table->index('operation_id');
        });

        $this->backfillFlowAndStatus();

        Schema::create('modification_items', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('modification_id');
            $table->string('key');
            $table->json('original')->nullable();
            $table->json('proposed')->nullable();
            $table->string('status')->default('pending');
            $table->integer('approvers_required')->default(1);
            $table->integer('disapprovers_required')->default(1);
            $table->json('context')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();

            $table->foreign('modification_id')->references('id')->on('modifications')->cascadeOnDelete();
            $table->unique(['modification_id', 'key']);
            $table->index('status');
            $table->index(['modification_id', 'status']);
        });

        Schema::table('approvals', function (Blueprint $table) {
            $table->unsignedInteger('modification_item_id')->nullable()->after('modification_id');
            $table->index('modification_item_id');
        });

        Schema::table('disapprovals', function (Blueprint $table) {
            $table->unsignedInteger('modification_item_id')->nullable()->after('modification_id');
            $table->index('modification_item_id');
        });
    }

    public function down(): void
    {
        Schema::table('disapprovals', function (Blueprint $table) {
            $table->dropIndex(['modification_item_id']);
        });

        Schema::table('disapprovals', function (Blueprint $table) {
            $table->dropColumn('modification_item_id');
        });

        Schema::table('approvals', function (Blueprint $table) {
            $table->dropIndex(['modification_item_id']);
        });

        Schema::table('approvals', function (Blueprint $table) {
            $table->dropColumn('modification_item_id');
        });

        Schema::dropIfExists('modification_items');

        Schema::table('modifications', function (Blueprint $table) {
            $table->dropIndex(['operation_id']);
        });

        Schema::table('modifications', function (Blueprint $table) {
            $table->dropColumn(['flow', 'status', 'event_type', 'operation_id', 'context', 'occurred_at']);
        });
    }

    /**
     * Existing rows only have `active`/`is_modification`; derive `flow`/`status`
     * from them (and, for resolved rows, from vote counts) so pre-existing
     * installations keep working without a manual data fix.
     */
    private function backfillFlowAndStatus(): void
    {
        DB::table('modifications')->orderBy('id')->each(function ($row) {
            $flow = $row->is_modification ? 'approval' : 'direct';

            if (!$row->is_modification) {
                $status = 'applied';
            } elseif ($row->active) {
                $status = 'pending';
            } else {
                $disapprovals = DB::table('disapprovals')->where('modification_id', $row->id)->whereNull('deleted_at')->count();
                $status = $disapprovals >= max($row->disapprovers_required, 1) ? 'rejected' : 'approved';
            }

            DB::table('modifications')->where('id', $row->id)->update(['flow' => $flow, 'status' => $status]);
        });
    }
};
