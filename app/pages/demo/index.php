<?php $title = 'Демо: счётчик — rig'; $layout = 'demo'; ?>
<header class="intro">
  <h1>Счётчик</h1>
  <p>
    Состояние, действия и вычисления описаны на PHP, а страница обновляется точечно.
    «+» меняет число сразу, сервер нарочно отвечает через 0,4 с.
  </p>
</header>

<div class="grid">
  <Counter label="Счётчик" />

  <Card title="Компонент прямо на странице">
    <p>Карточка вставлена тегом <code>&lt;Card&gt;</code> со слотами, так же как в шаблонах компонентов.</p>
    <template #footer>
      <span>Слот «footer»</span>
      <a href="/demo/todos">К задачам</a>
    </template>
  </Card>
</div>

<p class="hint">
  Откройте <code>app/components/Counter.php</code>, поменяйте шаблон или стиль и сохраните.
  Компонент обновится без перезагрузки страницы, значение счётчика останется.
</p>
