<?php

declare(strict_types=1);

namespace App\Components;

use Rig\Attr\Action;
use Rig\Component;
use Rig\I18n;
use Rig\Request;

/** i18n: t() в шаблоне, язык хранится в cookie; смена языка перезагружает страницу целиком. */
final class LangSwitch extends Component
{
    public string $name = 'rig';
    public string $locale = 'ru';

    public function mount(): void
    {
        $this->locale = I18n::locale();
    }

    #[Action]
    public function use(string $lang): void
    {
        I18n::setLocale($lang);
        $back = (string)parse_url((string)Request::current()->header('Referer'), PHP_URL_PATH);
        redirect($back !== '' ? $back : '/demo/ui');
    }
}
?>

<template>
  <Card :title="t('demo.lang')">
    <p class="hello">{{ t('demo.hello', {name: name}) }}</p>
    <p class="meta">{{ t('demo.items', {n: 1}) }} · {{ t('demo.items', {n: 3}) }} · {{ t('demo.items', {n: 5}) }}</p>
    <div class="row">
      <button r-for="l in ['ru', 'en']" :key="l" :class="{on: locale == l}" @click="use(l)">{{ upper(l) }}</button>
    </div>
  </Card>
</template>

<style>
  .hello { font-size: 20px; font-weight: 600; margin: 0 0 4px; }
  .meta { color: var(--muted); margin: 0 0 12px; }
  .row { display: flex; gap: 6px; }
  .row .on { border-color: var(--accent); color: var(--accent); }
</style>
