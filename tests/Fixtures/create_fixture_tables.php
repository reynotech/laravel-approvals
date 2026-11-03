<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

return function (): void {
    $schema = Capsule::schema();

    $schema->dropIfExists('approvable_models');
    $schema->create('approvable_models', function (Blueprint $table) {
        $table->increments('id');
        $table->string('name');
        $table->string('status')->default('draft');
        $table->string('rules')->nullable();
        $table->string('notes')->nullable();
        $table->timestamps();
    });

    $schema->dropIfExists('casted_models');
    $schema->create('casted_models', function (Blueprint $table) {
        $table->increments('id');
        $table->string('starts_at')->nullable();
        $table->string('starts_immutable')->nullable();
        $table->string('starts_on')->nullable();
        $table->text('as_array')->nullable();
        $table->text('as_collection')->nullable();
        $table->text('as_collection_class')->nullable();
        $table->text('as_array_object')->nullable();
        $table->text('extra')->nullable();
        $table->timestamps();
    });

    $schema->dropIfExists('regression_models');
    $schema->create('regression_models', function (Blueprint $table) {
        $table->increments('id');
        foreach (['name', 'status', 'notes', 'secret', 'stamp', 'payload'] as $column) {
            $table->text($column)->nullable();
        }
        $table->string('code')->nullable()->unique();
        $table->integer('price')->nullable();
        $table->binary('blob_value')->nullable();
        $table->boolean('approval_pending')->default(false);
        $table->softDeletes();
        $table->timestamps();
    });

    $schema->dropIfExists('approver_models');
    $schema->create('approver_models', function (Blueprint $table) {
        $table->increments('id');
        $table->string('name');
        $table->timestamps();
    });
};
