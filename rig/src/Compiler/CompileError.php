<?php

declare(strict_types=1);

namespace Rig\Compiler;

/** Ошибка в шаблоне или объявлении компонента. Указывает на строку в исходном файле. */
final class CompileError extends \RuntimeException
{
    public function __construct(string $message, string $file, int $line)
    {
        parent::__construct($message);
        $this->file = $file;
        $this->line = $line;
    }
}
