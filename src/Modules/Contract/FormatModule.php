<?php

declare(strict_types=1);

namespace Diff\Modules\Contract;

use Diff\Detect\FileProbe;

interface FormatModule
{
    public function id(): string;

    public function supports(FileProbe $probe): bool;

    public function contentKind(): string;

    public function parse(string $path): ParsedContent;
}
