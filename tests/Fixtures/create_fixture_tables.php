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

    $schema->dropIfExists('approver_models');
    $schema->create('approver_models', function (Blueprint $table) {
        $table->increments('id');
        $table->string('name');
        $table->timestamps();
    });
};
