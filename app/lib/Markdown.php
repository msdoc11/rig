<?php

declare(strict_types=1);

namespace App;

use Rig\Highlight;
use Rig\Renderer;

/**
 * Markdown для документации: заголовки с якорями, абзацы, списки, таблицы, цитаты-заметки,
 * блоки кода с подсветкой (Rig\Highlight), `код`, **жирный**, [ссылки](/docs/...).
 */
final class Markdown
{
    /** @var list<array{id:string, text:string}> заголовки h2 для оглавления «На этой странице» */
    public array $toc = [];
    public string $title = '';

    public function render(string $md): string
    {
        $lines = explode("\n", str_replace("\r\n", "\n", $md));
        $out = '';
        $n = count($lines);
        for ($i = 0; $i < $n; $i++) {
            $line = $lines[$i];

            if (preg_match('/^```(\w*)\s*$/', $line, $m)) {
                $code = [];
                for ($i++; $i < $n && !preg_match('/^```\s*$/', $lines[$i]); $i++) {
                    $code[] = $lines[$i];
                }
                $lang = $m[1] ?: 'text';
                $out .= '<figure class="code" data-lang="' . Renderer::e($lang) . '"><pre><code>'
                    . Highlight::html(implode("\n", $code), $lang) . '</code></pre></figure>';
                continue;
            }
            if (preg_match('/^(#{1,3})\s+(.+)$/', $line, $m)) {
                $level = strlen($m[1]);
                $text = trim($m[2]);
                if ($level === 1) {
                    $this->title = $text;
                    $out .= '<h1>' . $this->inline($text) . '</h1>';
                    continue;
                }
                $id = $this->slug($text);
                if ($level === 2) {
                    $this->toc[] = ['id' => $id, 'text' => strip_tags($this->inline($text))];
                }
                $out .= "<h{$level} id=\"{$id}\"><a class=\"anchor\" href=\"#{$id}\" aria-hidden=\"true\" tabindex=\"-1\">#</a>" . $this->inline($text) . "</h{$level}>";
                continue;
            }
            if (str_starts_with($line, '|')) {
                $rows = [];
                for (; $i < $n && str_starts_with($lines[$i], '|'); $i++) {
                    $rows[] = $lines[$i];
                }
                $i--;
                $out .= $this->table($rows);
                continue;
            }
            if (preg_match('/^>\s?(.*)$/', $line)) {
                $quote = [];
                for (; $i < $n && preg_match('/^>\s?(.*)$/', $lines[$i], $q); $i++) {
                    $quote[] = $q[1];
                }
                $i--;
                $out .= '<aside class="note">' . $this->paragraphs(implode("\n", $quote)) . '</aside>';
                continue;
            }
            if (preg_match('/^(\s*)([-*]|\d+\.)\s+/', $line, $m)) {
                $ordered = ctype_digit($m[2][0]);
                $items = [];
                for (; $i < $n; $i++) {
                    if (preg_match('/^([-*]|\d+\.)\s+(.*)$/', $lines[$i], $li)) {
                        $items[] = $li[2];
                    } elseif ($items && preg_match('/^\s{2,}(\S.*)$/', $lines[$i], $cont)) {
                        $items[count($items) - 1] .= ' ' . $cont[1]; // продолжение пункта
                    } else {
                        break;
                    }
                }
                $i--;
                $tag = $ordered ? 'ol' : 'ul';
                $out .= "<{$tag}>" . implode('', array_map(fn (string $t) => '<li>' . $this->inline($t) . '</li>', $items)) . "</{$tag}>";
                continue;
            }
            if (trim($line) === '') {
                continue;
            }
            $para = [];
            for (; $i < $n && trim($lines[$i]) !== '' && !preg_match('/^(```|#{1,3}\s|\||>|([-*]|\d+\.)\s)/', $lines[$i]); $i++) {
                $para[] = $lines[$i];
            }
            $i--;
            $out .= '<p>' . $this->inline(implode(' ', $para)) . '</p>';
        }
        return $out;
    }

    private function paragraphs(string $text): string
    {
        return implode('', array_map(fn (string $p) => '<p>' . $this->inline(str_replace("\n", ' ', $p)) . '</p>', preg_split('/\n\s*\n/', trim($text)) ?: []));
    }

    private function table(array $rows): string
    {
        $cells = static fn (string $row): array => array_map('trim', explode('|', trim(trim($row), '|')));
        $head = $cells($rows[0]);
        $body = array_slice($rows, 2);
        $html = '<div class="table"><table><thead><tr>' . implode('', array_map(fn ($c) => '<th>' . $this->inline($c) . '</th>', $head)) . '</tr></thead><tbody>';
        foreach ($body as $row) {
            $html .= '<tr>' . implode('', array_map(fn ($c) => '<td>' . $this->inline($c) . '</td>', $cells($row))) . '</tr>';
        }
        return $html . '</tbody></table></div>';
    }

    /** `код`, **жирный**, [текст](адрес). Всё остальное экранируется. */
    public function inline(string $text): string
    {
        $codes = [];
        $text = (string)preg_replace_callback('/`([^`]+)`/', static function (array $m) use (&$codes): string {
            $codes[] = '<code>' . Renderer::e($m[1]) . '</code>';
            return "\x01" . (count($codes) - 1) . "\x01";
        }, $text);
        $html = Renderer::e($text);
        $html = (string)preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $html);
        $html = (string)preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', static function (array $m): string {
            $external = preg_match('~^https?://~', html_entity_decode($m[2])) === 1;
            return '<a href="' . $m[2] . '"' . ($external ? ' target="_blank" rel="noopener"' : '') . '>' . $m[1] . '</a>';
        }, $html);
        return (string)preg_replace_callback('/\x01(\d+)\x01/', static fn (array $m) => $codes[(int)$m[1]], $html);
    }

    private function slug(string $text): string
    {
        $s = mb_strtolower(strip_tags($this->inline($text)));
        $s = (string)preg_replace('/[^\p{L}\p{N}]+/u', '-', $s);
        return trim($s, '-') ?: 'section';
    }
}
