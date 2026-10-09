<?php

declare(strict_types=1);

namespace Rig\Attr;

/**
 * Middleware для компонента или одного действия. На классе проверяется при показе компонента
 * и перед каждым его действием, как бы компонент ни попал на страницу. На методе проверяется перед этим действием.
 *
 *   #[Guard('auth')]
 *   final class Profile extends Component { … }
 *
 *   #[Action, Guard('role:admin')]
 *   public function deleteUser(int $id): void { … }
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final class Guard
{
    /** @var list<string> */
    public readonly array $middleware;

    public function __construct(string ...$middleware)
    {
        $this->middleware = array_values($middleware);
    }
}
