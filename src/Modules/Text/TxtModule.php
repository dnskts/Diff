<?php

declare(strict_types=1);

namespace Diff\Modules\Text;

use Diff\AppException;
use Diff\Detect\FileProbe;
use Diff\Modules\Contract\FormatModule;
use Diff\Modules\Contract\ParsedContent;
use Diff\Support\Encoding;

class TxtModule implements FormatModule
{
    /** @var array<string,mixed> */
    private $config;

    /** @param array<string,mixed> $config */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function id(): string
    {
        return 'txt';
    }

    public function contentKind(): string
    {
        return 'text';
    }

    public function supports(FileProbe $probe): bool
    {
        return $probe->extension === 'txt';
    }

    public function parse(string $path): ParsedContent
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new AppException('Не удалось прочитать TXT.');
        }
        $text = str_replace(array("\r\n", "\r"), "\n", Encoding::toUtf8($raw));
        $lines = explode("\n", $text);
        $max = (int) $this->config['max_blocks'];
        if (count($lines) > $max) {
            throw new AppException('Слишком много строк в TXT (лимит ' . $max . ').');
        }
        $parsed = new ParsedContent();
        $parsed->kind = 'text';
        $parsed->moduleId = $this->id();
        $parsed->blocks = $lines;
        $parsed->meta = array('encoding' => 'utf-8');

        return $parsed;
    }
}
