<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each table is guarded with hasTable() so this migration is a no-op for
     * apps that already had an equivalently-shaped `modifications`/`approvals`/
     * `disapprovals` schema before adopting this package (e.g. apps migrating
     * off a hand-rolled predecessor of this exact workflow). The following
     * migration (flow/status/modification_items) then applies normally on top.
     */
    public function up(): void
    {
        if (! Schema::hasTable('modifications')) {
            Schema::create('modifications', function (Blueprint $table) {
                $table->increments('id');
                $table->boolean('is_modification')->default(true);
                $table->nullableMorphs('modifiable');
                $table->nullableMorphs('modifier');
                $table->boolean('active')->default(true);
                $table->boolean('is_update')->default(true);
                $table->integer('approvers_required')->default(1);
                $table->integer('disapprovers_required')->default(1);
                $table->string('md5');
                $table->json('modifications');
                $table->json('meta')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('approvals')) {
            Schema::create('approvals', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('modification_id');
                $table->morphs('approver');
                $table->text('reason')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('modification_id')->references('id')->on('modifications')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('disapprovals')) {
            Schema::create('disapprovals', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('modification_id');
                $table->morphs('disapprover');
                $table->text('reason')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('modification_id')->references('id')->on('modifications')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('disapprovals');
        Schema::dropIfExists('approvals');
        Schema::dropIfExists('modifications');
    }
};
