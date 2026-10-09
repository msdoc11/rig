<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?= Rig\Page::head() ?>
  <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='7' fill='%231C211E'/%3E%3Ccircle cx='21.5' cy='10.5' r='4.5' fill='%23FFB703'/%3E%3C/svg%3E">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Tektur:wdth,wght@75..100,400..900&family=Golos+Text:wght@400..700&family=JetBrains+Mono:wght@400;500;600&display=swap">
  <style>
    /* ------------------------------------------------------------ токены
       Идея: стенд (rig). Компонент закреплён на стенде, к браузеру идёт провод.
       Эмаль: поверхность стенда. Графит: пластины с кодом (сервер). Кобальт: то, что можно нажать.
       Лампа: сигнал «идёт запрос / PHP работает». */
    :root {
      --bg: #E4E7E0; --bg-2: #D6DBD2; --plate: #F4F5F1;
      --ink: #1A1E1C; --ink-2: #434A45; --muted: #646C66; --line: #C3CAC0;
      --graphite: #1C211E; --graphite-2: #272D29; --graphite-line: #353C37; --on-graphite: #E8ECE5; --on-graphite-muted: #8C958E;
      --cobalt: #2B3BD8; --cobalt-ink: #2231B5; --cobalt-soft: #D9DDF7; --on-cobalt: #FFFFFF;
      --lamp: #FFB703; --lamp-soft: #FBE7B0; --lamp-ink: #6E4A00;
      --c-kw: #A9B5FF; --c-str: #BFE3A1; --c-var: #FFC94D; --c-num: #FFC94D; --c-cm: #7A847D; --c-attr: #8AD5CA; --c-fn: #F4F6F0; --c-dir: #FFAA88;
      --display: "Tektur", "Bahnschrift", "Segoe UI", system-ui, sans-serif;
      --text: "Golos Text", "Segoe UI", system-ui, -apple-system, sans-serif;
      --mono: "JetBrains Mono", ui-monospace, "Cascadia Code", Consolas, monospace;
      --header: 60px;
      color-scheme: light;
    }
    @media (prefers-color-scheme: dark) {
      :root {
        --bg: #121513; --bg-2: #0C0E0D; --plate: #1A1E1B;
        --ink: #E6EAE3; --ink-2: #B7BEB6; --muted: #8B938C; --line: #2C322E;
        --graphite: #0A0C0B; --graphite-2: #151917; --graphite-line: #29302B;
        --cobalt: #8E9AFF; --cobalt-ink: #AAB3FF; --cobalt-soft: #1D2250; --on-cobalt: #0C0E0D;
        --lamp: #FFC233; --lamp-soft: #3A2E0A; --lamp-ink: #FFC233;
        color-scheme: dark;
      }
    }

    /* ------------------------------------------------------------ база */
    *, *::before, *::after { box-sizing: border-box; }
    html { scroll-padding-top: calc(var(--header) + 16px); -webkit-text-size-adjust: 100%; }
    body { margin: 0; background: var(--bg); color: var(--ink); font: 400 17px/1.6 var(--text); }
    a { color: var(--cobalt-ink); text-underline-offset: 3px; }
    a:hover { text-decoration-thickness: 2px; }
    :focus-visible { outline: 2px solid var(--cobalt); outline-offset: 3px; border-radius: 3px; }
    code, pre, kbd { font-family: var(--mono); font-variant-ligatures: none; }
    img { max-width: 100%; height: auto; }
    .wrap { width: min(1200px, 100% - 40px); margin-inline: auto; }
    .docs-nav, pre, .table { scrollbar-width: thin; scrollbar-color: color-mix(in srgb, var(--muted) 45%, transparent) transparent; }
    .sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }

    /* подсветка кода (Rig\Highlight) на графитовых пластинах */
    .kw, .tag { color: var(--c-kw); } .str, .expr { color: var(--c-str); } .var, .num, .cmp { color: var(--c-var); }
    .cm { color: var(--c-cm); font-style: italic; } .attr { color: var(--c-attr); } .fn { color: var(--c-fn); } .dir { color: var(--c-dir); }

    /* ------------------------------------------------------------ шапка */
    .site-head { position: sticky; top: 0; z-index: 20; height: var(--header); background: var(--bg); border-bottom: 1px solid var(--line); }
    .site-head .wrap { height: 100%; display: flex; align-items: center; gap: 32px; }
    .brand { display: inline-flex; align-items: center; gap: 10px; color: var(--ink); text-decoration: none;
      font: 800 25px/1 var(--display); font-stretch: 85%; letter-spacing: -0.01em; }
    .brand-mark { width: 24px; height: 24px; border-radius: 5px; background: var(--graphite); position: relative; flex: none; box-shadow: inset 0 0 0 1px var(--graphite-line); }
    .brand-mark::after { content: ""; position: absolute; right: 4px; top: 4px; width: 8px; height: 8px; border-radius: 50%; background: var(--lamp); }
    .site-nav { display: flex; gap: 22px; height: 100%; }
    .site-nav a { display: flex; align-items: center; color: var(--ink-2); text-decoration: none; font-weight: 500; border-bottom: 2px solid transparent; margin-bottom: -1px; }
    .site-nav a:hover { color: var(--ink); }
    .site-nav a[aria-current="page"], .site-nav a.on { color: var(--ink); border-bottom-color: var(--cobalt); }
    .site-head .version { margin-left: auto; color: var(--muted); font: 500 13px var(--mono); }
    html[r-loading]::before { content: ""; position: fixed; inset: 0 0 auto; height: 2px; z-index: 50; background: var(--lamp);
      transform-origin: left; animation: loading 1s ease-out infinite; }
    @keyframes loading { from { transform: scaleX(0); } to { transform: scaleX(1); } }

    /* ------------------------------------------------------------ кнопки и команды */
    .btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 48px; padding: 0 22px;
      border-radius: 8px; font: 600 16px var(--text); text-decoration: none; border: 1.5px solid transparent; cursor: pointer;
      transition: background-color .15s ease, border-color .15s ease, color .15s ease; }
    .btn:active { transform: translateY(1px); }
    .btn-primary { background: var(--cobalt); color: var(--on-cobalt); }
    .btn-primary:hover { background: var(--cobalt-ink); text-decoration: none; }
    .btn-ghost { color: var(--ink); border-color: var(--ink); background: transparent; }
    .btn-ghost:hover { background: var(--ink); color: var(--bg); text-decoration: none; }

    .hero-actions { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; }
    /* ------------------------------------------------------------ секции */
    .section { padding: clamp(64px, 9vw, 120px) 0; }
    .section-alt { background: var(--bg-2); }
    .section-head { max-width: 40em; margin-bottom: clamp(32px, 4vw, 56px); }
    .section h2 { font: 700 clamp(2rem, 4.2vw, 3.4rem)/1 var(--display); font-stretch: 80%; letter-spacing: -0.01em; margin: 0 0 16px; }
    .section-head p { color: var(--ink-2); margin: 0; font-size: 1.08rem; }
    .muted { color: var(--ink-2); }

    /* подвал */
    .site-foot { padding: 24px 0 36px; color: var(--muted); font-size: 14px; border-top: 1px solid var(--line); }
    .site-foot .wrap { display: flex; gap: 20px; flex-wrap: wrap; justify-content: space-between; }
    .site-foot a { color: var(--muted); }
    .foot-links { display: flex; gap: 20px; flex-wrap: wrap; }

    /* страницы ошибок */
    .error-page { min-height: calc(100vh - var(--header) - 120px); }
    .error-code { margin: 0; font: 900 clamp(5.5rem, 18vw, 12rem)/0.85 var(--display); font-stretch: 75%; color: var(--ink); }
    .error-page h1 { font: 700 clamp(1.9rem, 3.6vw, 2.8rem)/1.1 var(--display); font-stretch: 85%; margin: 18px 0 10px; }
    .error-page p { color: var(--ink-2); margin: 0 0 28px; max-width: 34em; }

    /* ------------------------------------------------------------ документация */
    .docs { display: grid; grid-template-columns: 240px minmax(0, 1fr) 200px; gap: clamp(24px, 4vw, 56px); padding: 40px 0 80px; }
    .docs-nav { position: sticky; top: calc(var(--header) + 24px); align-self: start; max-height: calc(100vh - var(--header) - 48px); overflow: auto; padding-right: 8px; }
    .docs-nav summary { display: none; }
    .docs-group { margin: 22px 0 6px; font: 600 13px var(--text); color: var(--muted); }
    .docs-group:first-of-type { margin-top: 0; }
    .docs-nav ul { list-style: none; margin: 0; padding: 0; }
    .docs-nav a { display: block; padding: 6px 12px; border-radius: 6px; color: var(--ink-2); text-decoration: none; font-size: 15px; border-left: 2px solid transparent; }
    .docs-nav a:hover { background: var(--bg-2); color: var(--ink); }
    .docs-nav a[aria-current="page"] { background: var(--plate); color: var(--ink); font-weight: 600; border-left-color: var(--cobalt); border-radius: 0 6px 6px 0; }
    .toc { position: sticky; top: calc(var(--header) + 24px); align-self: start; font-size: 14px; }
    .toc p { margin: 0 0 8px; font-weight: 600; color: var(--muted); font-size: 13px; }
    .toc ul { list-style: none; margin: 0; padding: 0; border-left: 1px solid var(--line); }
    .toc a { display: block; padding: 4px 0 4px 12px; color: var(--muted); text-decoration: none; }
    .toc a:hover { color: var(--ink); }

    .prose { max-width: 46em; min-width: 0; }
    .prose h1 { font: 800 clamp(2.3rem, 4.4vw, 3.4rem)/1 var(--display); font-stretch: 78%; margin: 0 0 20px; }
    .prose h2 { font: 700 1.75rem/1.15 var(--display); font-stretch: 85%; margin: 52px 0 14px; position: relative; }
    .prose h3 { font: 600 1.15rem/1.3 var(--text); margin: 32px 0 10px; position: relative; }
    .prose .anchor { position: absolute; left: -1.1em; color: var(--muted); text-decoration: none; opacity: 0; font-weight: 400; }
    .prose h2:hover .anchor, .prose h3:hover .anchor, .prose .anchor:focus-visible { opacity: 1; }
    .prose p, .prose li { color: var(--ink-2); }
    .prose p { margin: 0 0 16px; }
    .prose ul, .prose ol { padding-left: 1.3em; margin: 0 0 18px; }
    .prose li { margin: 6px 0; }
    .prose li::marker { color: var(--muted); }
    .prose strong { color: var(--ink); font-weight: 600; }
    .prose :not(pre) > code { font-size: .86em; padding: .12em .38em; border-radius: 4px; background: var(--plate); color: var(--ink); box-shadow: inset 0 0 0 1px var(--line); }
    .prose figure.code { position: relative; margin: 20px 0 24px; border-radius: 8px; background: var(--graphite); color: var(--on-graphite); overflow: hidden; box-shadow: inset 0 0 0 1px var(--graphite-line); }
    .prose figure.code::before { content: attr(data-lang); position: absolute; top: 8px; right: 12px; font: 500 11px var(--mono); color: var(--on-graphite-muted); }
    .prose figure.code pre { margin: 0; padding: 18px 20px; overflow-x: auto; font: 400 13.5px/1.7 var(--mono); }
    .prose .table { overflow-x: auto; margin: 20px 0 24px; border-top: 1.5px solid var(--ink); }
    .prose table { border-collapse: collapse; width: 100%; font-size: 15px; }
    .prose th, .prose td { text-align: left; padding: 11px 14px 11px 0; border-bottom: 1px solid var(--line); vertical-align: top; }
    .prose th { font-weight: 600; color: var(--ink); }
    .prose td code, .prose th code { white-space: nowrap; }
    .prose .note { margin: 20px 0; padding: 14px 18px; border-radius: 0 8px 8px 0; background: var(--lamp-soft); border-left: 3px solid var(--lamp); }
    .prose .note p { margin: 0; color: var(--ink); }
    .pager { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-top: 56px; }
    .pager a { display: block; padding: 16px 18px; border-radius: 8px; text-decoration: none; background: var(--plate); box-shadow: inset 0 0 0 1px var(--line); }
    .pager a:hover { box-shadow: inset 0 0 0 1.5px var(--cobalt); }
    .pager span { display: block; color: var(--muted); font-size: 13px; }
    .pager strong { color: var(--ink); font-weight: 600; }
    .pager .next { text-align: right; grid-column: 2; }

    /* ------------------------------------------------------------ узкие экраны */
    @media (max-width: 1080px) {
      .docs { grid-template-columns: 220px minmax(0, 1fr); }
      .toc { display: none; }
    }
    @media (max-width: 760px) {
      .site-head .version { display: none; }
      .site-head .wrap { gap: 20px; }
      .docs { grid-template-columns: minmax(0, 1fr); padding-top: 20px; }
      .docs-nav { position: static; max-height: none; border-radius: 8px; padding: 4px 12px; background: var(--plate); box-shadow: inset 0 0 0 1px var(--line); }
      .docs-nav summary { display: block; cursor: pointer; padding: 10px 2px; font-weight: 600; }
      .docs-nav:not([open]) > :not(summary) { display: none; }
      .pager { grid-template-columns: 1fr; }
      .pager .next { grid-column: 1; }
    }
    @media (prefers-reduced-motion: reduce) {
      *, *::before, *::after { animation-duration: .01ms !important; animation-iteration-count: 1 !important; transition-duration: .01ms !important; scroll-behavior: auto !important; }
    }
  </style>
  <?= Rig\Page::styles() ?>
</head>
<body>
<header class="site-head">
  <div class="wrap">
    <a class="brand" href="/" aria-label="rig, на главную"><span class="brand-mark" aria-hidden="true"></span>rig</a>
    <nav class="site-nav" aria-label="Разделы сайта">
      <a href="/docs" r-prefetch="eager">Документация</a>
      <a href="/demo" r-reload>Демо</a>
    </nav>
    <span class="version">v<?= Rig\App::VERSION ?></span>
  </div>
</header>

<?= $content ?>

<footer class="site-foot">
  <div class="wrap">
    <span>rig v<?= Rig\App::VERSION ?>, реактивный интерфейс на PHP</span>
    <nav class="foot-links" aria-label="Ссылки"><a href="/docs">Документация</a><a href="/docs/start">Быстрый старт</a><a href="/demo" r-reload>Демо</a></nav>
  </div>
</footer>

<?= Rig\Page::scripts() ?>
</body>
</html>
