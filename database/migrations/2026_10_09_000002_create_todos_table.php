<?php

use Rig\Db\Schema;
use Rig\Db\Table;

return new class {
    public function up(): void
    {
        Schema::create('todos', function (Table $t) {
            $t->id();
            $t->string('title', 200);
            $t->boolean('done')->default(false);
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
            $t->index('done');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('todos');
    }
};
