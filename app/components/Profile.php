<?php

declare(strict_types=1);

namespace App\Components;

use App\ActivityLog;
use App\Models\User;
use Rig\Attr\Action;
use Rig\Attr\Guard;
use Rig\Attr\Locked;
use Rig\Auth;
use Rig\Component;
use Rig\UploadedFile;

/**
 * Личный кабинет: данные из базы, загрузка аватара, выход.
 * #[Guard('auth')]: показ и каждое действие доступны только вошедшим, как бы компонент ни попал на страницу.
 */
#[Guard('auth')]
final class Profile extends Component
{
    #[Locked]
    public array $user = [];

    public string $name = '';

    /** Выбранный файл: уходит на сервер сразу после выбора, сохраняется действием saveAvatar(). */
    public ?UploadedFile $photo = null;

    public function mount(): void
    {
        $this->user = Auth::user() ?? [];
        $this->name = (string)($this->user['name'] ?? '');
    }

    #[Action]
    public function saveName(): void
    {
        $this->validate(['name' => 'required|min:2|max:60']);
        User::findOrFail(Auth::id())->update(['name' => trim($this->name)]);
        $this->user = Auth::user() ?? [];
        $this->user['name'] = trim($this->name);
    }

    #[Action]
    public function saveAvatar(): void
    {
        $this->validate(['photo' => 'required|image|max:2048']);
        $url = $this->photo->store('avatars');
        User::findOrFail(Auth::id())->update(['avatar' => $url]);
        $this->user['avatar'] = $url;
        $this->photo = null;
        ActivityLog::add("Новый аватар: {$this->user['name']}");
        $this->dispatch('activity');   // ленте на этой странице
        $this->broadcast('activity');  // и в остальных вкладках
    }

    #[Action]
    public function logout(): void
    {
        ActivityLog::add("Выход: {$this->user['name']}");
        $this->broadcast('activity');
        Auth::logout();
        redirect('/login');
    }
}
?>

<template>
  <Card title="Профиль">
    <div class="who">
      <img r-if="user.avatar" :src="user.avatar" alt="" class="avatar">
      <span r-else class="avatar empty" aria-hidden="true">{{ upper(slice(user.name, 0, 1)) }}</span>
      <div>
        <p class="name">{{ user.name }}</p>
        <p class="meta">{{ user.email }} · роль: {{ user.role }}</p>
      </div>
    </div>

    <form class="row" @submit.prevent="saveName">
      <input r-model="name" aria-label="Имя">
      <button :disabled="name == user.name">Сохранить имя</button>
    </form>
    <p r-if="$errors.name" class="error">{{ $errors.name }}</p>

    <div class="upload">
      <label class="pick">
        <input type="file" accept="image/*" r-model="photo">
        <span>{{ photo ? photo.name : 'Выбрать картинку для аватара' }}</span>
      </label>
      <img r-if="photo.preview" :src="photo.preview" alt="Превью" class="preview">
      <button r-if="photo" @click="saveAvatar" :disabled="$busy">{{ $pending.saveAvatar ? 'Сохраняем…' : 'Сохранить аватар' }}</button>
    </div>
    <p r-if="$errors.photo" class="error">{{ $errors.photo }}</p>

    <template #footer>
      <span>Вход защищён: Page::middleware('auth') и #[Guard('auth')]</span>
      <button class="link" @click="logout">Выйти</button>
    </template>
  </Card>
</template>

<style>
  .who { display: flex; gap: 14px; align-items: center; margin-bottom: 16px; }
  .avatar { width: 56px; height: 56px; border-radius: 50%; object-fit: cover; flex: none; }
  .avatar.empty { display: grid; place-items: center; background: var(--chip); font-weight: 700; font-size: 22px; }
  .name { margin: 0; font-weight: 600; font-size: 18px; }
  .meta { margin: 0; color: var(--muted); font-size: 14px; }
  .row { display: flex; gap: 8px; }
  .row input { flex: 1; min-width: 0; min-height: 40px; padding: 6px 10px; border: 1px solid var(--line); border-radius: 8px; background: var(--bg); color: var(--text); font: inherit; }
  .upload { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-top: 14px; }
  .pick { position: relative; display: inline-flex; align-items: center; min-height: 40px; padding: 6px 14px; border: 1px dashed var(--muted); border-radius: 8px; cursor: pointer; color: var(--muted); }
  .pick input { position: absolute; inset: 0; opacity: 0; cursor: pointer; }
  .pick:focus-within { outline: 2px solid var(--accent); outline-offset: 2px; }
  .preview { width: 56px; height: 56px; border-radius: 50%; object-fit: cover; }
  .error { color: var(--danger); margin: 6px 0 0; font-size: 14px; }
</style>
