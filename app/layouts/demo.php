<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?= Rig\Page::head() ?>
  <link rel="icon" href="data:,">  <!-- без иконки браузер просит /favicon.ico и получает страницу 404 -->

  <style>
    :root {
      --bg: #f6f5f2; --card: #fff; --text: #1d1d1b; --muted: #6f6c66; --line: #ebe8e2;
      --accent: #2f5bd3; --chip: #ecebe6; --danger: #c2372e;
      color-scheme: light;
    }
    @media (prefers-color-scheme: dark) {
      :root {
        --bg: #151515; --card: #1f1f1e; --text: #ecebe7; --muted: #9b978f; --line: #2d2c2a;
        --accent: #7c9cff; --chip: #2e2d2b; --danger: #ff7b6e;
        color-scheme: dark;
      }
    }
    * { box-sizing: border-box; }
    body { margin: 0; background: var(--bg); color: var(--text); font: 16px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; padding: 0 16px; }

    .top { max-width: 1080px; margin: 0 auto; display: flex; align-items: center; gap: 24px; padding: 20px 0; flex-wrap: wrap; }
    .logo { font-weight: 700; font-size: 20px; letter-spacing: -0.02em; color: inherit; text-decoration: none; }
    .top nav { display: flex; gap: 4px; }
    .top nav a { color: var(--muted); text-decoration: none; padding: 6px 12px; border-radius: 8px; }
    .top nav a:hover { color: var(--text); }
    .top nav a[aria-current="page"] { background: var(--chip); color: var(--text); }
    .top .back { margin-left: auto; color: var(--muted); text-decoration: none; }
    .top .back:hover { color: var(--text); }

    .layout { max-width: 1080px; margin: 0 auto; display: grid; grid-template-columns: minmax(0, 1fr) 320px; gap: 24px; align-items: start; padding-bottom: 48px; }
    @media (max-width: 860px) { .layout { grid-template-columns: minmax(0, 1fr); } }
    .intro h1 { font-size: 32px; margin: 8px 0 4px; letter-spacing: -0.02em; }
    .intro p { margin: 0 0 24px; color: var(--muted); max-width: 62ch; }
    .grid { display: grid; gap: 16px; }
    .hint { color: var(--muted); font-size: 14px; }

    button, input { font: inherit; color: inherit; }
    button { min-height: 40px; padding: 6px 14px; border: 1px solid var(--line); border-radius: 8px; background: var(--chip); cursor: pointer; }
    button:hover:not(:disabled) { border-color: var(--muted); }
    button:disabled { opacity: .45; cursor: default; }
    button:focus-visible, input:focus-visible, a:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
    button.link { background: none; border: 0; padding: 0; min-height: 0; color: var(--accent); }
    button.ghost { background: none; }
    input[type=text], input:not([type]), input[type=number] { min-height: 40px; padding: 6px 10px; border: 1px solid var(--line); border-radius: 8px; background: var(--bg); }
    input[type=checkbox] { width: 18px; height: 18px; accent-color: var(--accent); }
    code { font: 13px ui-monospace, monospace; background: var(--chip); padding: 2px 6px; border-radius: 4px; }
    [r-busy] { cursor: progress; }

    .error-page { padding: 48px 0; max-width: 52ch; }
    .error-page .code { font-size: 64px; font-weight: 700; line-height: 1; margin: 0; color: var(--muted); letter-spacing: -0.03em; }
    .error-page h1 { font-size: 28px; margin: 12px 0 8px; }
    .error-page p { color: var(--muted); margin: 0 0 8px; }
    .error-page .actions { display: flex; gap: 16px; align-items: center; margin-top: 24px; }
    .error-page a { color: var(--accent); }
    .error-page a.button { background: var(--text); color: var(--bg); padding: 10px 16px; border-radius: 8px; text-decoration: none; }

    /* полоска загрузки при переходе между страницами */
    html[r-loading]::before { content: ""; position: fixed; inset: 0 0 auto; height: 2px; background: var(--accent); animation: rig-load 1s ease-out infinite; z-index: 10; }
    @keyframes rig-load { from { transform: scaleX(0); transform-origin: left; } to { transform: scaleX(1); transform-origin: left; } }
    @media (prefers-reduced-motion: reduce) { html[r-loading]::before { animation: none; } }
  </style>
  <?= Rig\Page::styles() ?>
</head>
<body>
<header class="top">
  <a class="logo" href="/">rig</a>
  <nav aria-label="Демо">
    <!-- r-prefetch="eager": разделы меню грузятся заранее, как только страница открылась -->
    <a href="/demo" r-prefetch="eager">Счётчик</a>
    <a href="/demo/todos" r-prefetch="eager">Задачи</a>
    <a href="/demo/products" r-prefetch="eager">Каталог</a>
    <a href="/demo/ui" r-prefetch="eager">Интерфейс</a>
    <?php if (Rig\Auth::check()): ?>
      <a href="/demo/account">Кабинет</a>
    <?php else: ?>
      <a href="/login">Вход</a>
    <?php endif ?>
  </nav>
  <a class="back" href="/docs">Документация</a>
</header>

<div class="layout">
  <main><?= $content ?></main>
  <aside><ActivityFeed /></aside>
</div>

<?= Rig\Page::scripts() ?>
</body>
</html>
