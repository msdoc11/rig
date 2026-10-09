<?php

use Rig\Db\Schema;
use Rig\Db\Table;

return new class {
    public function up(): void
    {
        Schema::create('users', function (Table $t) {
            $t->id();
            $t->string('name');
            $t->string('email')->unique();
            $t->string('password');
            $t->string('role')->default('user');
            $t->string('avatar')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
