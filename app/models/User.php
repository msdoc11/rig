<?php

declare(strict_types=1);

namespace App\Models;

use Rig\Db\Model;
use Rig\Db\Query;

final class User extends Model
{
    /** Роль задаётся отдельно: из формы её не подставить. */
    protected static array $fillable = ['name', 'email', 'password', 'avatar'];
    protected static array $hidden = ['password'];

    public function todos(): Query
    {
        return $this->hasMany(Todo::class);
    }
}
