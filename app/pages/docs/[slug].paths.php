<?php

/** Для rig generate: какие разделы документации собрать в статические страницы /docs/{slug}. */
return array_values(array_filter(array_merge(...array_map('array_keys', array_values(App\Docs::NAV))), static fn ($s) => $s !== ''));
