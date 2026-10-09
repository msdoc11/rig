<?php

declare(strict_types=1);

namespace Rig;

/**
 * Серверное состояние компонента между запросами хранится на странице,
 * подписанное HMAC. Подделать его, не зная ключа приложения, нельзя.
 * Вместе с состоянием подписан список middleware страницы, на которой показан компонент:
 * действия компонента проверяются теми же middleware.
 */
final class Snapshot
{
    /** @param list<string> $middleware */
    public static function sign(string $component, array $state, string $key, array $middleware = []): string
    {
        $data = ['c' => $component, 's' => $state];
        if ($middleware) {
            $data['m'] = array_values($middleware);
        }
        $payload = self::b64(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return $payload . '.' . self::b64(hash_hmac('sha256', $payload, $key, true));
    }

    public static function verify(string $component, string $token, string $key): array
    {
        return self::open($component, $token, $key)[0];
    }

    /** @return array{0:array,1:list<string>} состояние и middleware страницы */
    public static function open(string $component, string $token, string $key): array
    {
        [$payload, $sig] = array_pad(explode('.', $token, 2), 2, '');
        if (!hash_equals(self::b64(hash_hmac('sha256', $payload, $key, true)), $sig)) {
            throw new HttpError(419, 'Подпись состояния не совпала. Обновите страницу.');
        }
        $data = json_decode((string)base64_decode(strtr($payload, '-_', '+/')), true);
        if (!is_array($data) || ($data['c'] ?? null) !== $component || !is_array($data['s'] ?? null)) {
            throw new HttpError(419, 'Состояние принадлежит другому компоненту');
        }
        return [$data['s'], array_values(array_map('strval', (array)($data['m'] ?? [])))];
    }

    /**
     * Подпись одного имени события для хаба: #[On('chat.{roomId}')] после подстановки значений, например chat.5.
     * Браузер присылает хабу имя вместе с подписью, поэтому подписаться на чужую комнату он не может.
     */
    public static function topic(string $topic, string $key): string
    {
        return self::b64(substr(hash_hmac('sha256', "topic\0{$topic}", $key, true), 0, 16));
    }

    private static function b64(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }
}
