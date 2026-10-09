<?php

use Rig\Db\Model;

final class Todo extends Model
{
    protected static array $casts = ['done' => 'bool', 'tags' => 'array'];

    public function owner(): ?Model
    {
        return $this->belongsTo(TestUser::class, 'user_id');
    }
}

final class TestUser extends Model
{
    protected static ?string $table = 'users';
    protected static array $hidden = ['password'];
    protected static array $fillable = ['email', 'password'];

    public function todos(): Rig\Db\Query
    {
        return $this->hasMany(Todo::class, 'user_id');
    }
}
