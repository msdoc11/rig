<?php

declare(strict_types=1);

namespace App\Models;

use Rig\Db\Model;

final class Todo extends Model
{
    protected static array $fillable = ['title', 'done', 'user_id'];
    protected static array $casts = ['done' => 'bool', 'id' => 'int', 'user_id' => 'int'];
}
