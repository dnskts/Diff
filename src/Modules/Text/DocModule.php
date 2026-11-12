<?php

declare(strict_types=1);

namespace Diff\Modules\Text;

use Diff\AppException;
use Diff\Detect\FileProbe;
use Diff\Modules\Contract\FormatModule;
use Diff\Modules\Contract\ParsedContent;
use Diff\Support\Encoding;

class DocModule implements FormatModule
{
    public function id(): string
    {
        return 'doc';
    }

    public function contentKind(): string
    {
        return 'text';
    }

    public function supports(FileProbe $probe): bool
    {
        return $probe->extension === 'doc';
    }

    public function parse(string $path): ParsedContent
    {
        $probe = FileProbe::fromPath($path, 'file.doc');
        $blocks = array();
        $warning = null;
        if ($probe->looksLikeRtf()) {
            $blocks = $this->fromRtf($path);
        } elseif ($probe->looksLikeHtml()) {
            $blocks = $this->fromHtml($path);
        } else {
            // PhpWord MsDoc/OLERead зависает на битых compound-файлах — не вызываем.
            // Best-effort: эвристика по бинарнику + явная ошибка, если текста нет.
            $blocks = $this->fromBinary($path);
            if (count($blocks) > 0) {
                $warning = 'Текст DOC извлечён приблизительно. Для точного сравнения сохраните файл как DOCX или TXT.';
            }
        }
        if (count($blocks) === 0) {
            throw new AppException('Не удалось прочитать DOC. Сохраните файл как DOCX или TXT.');
        }
        $parsed = new ParsedContent();
        $parsed->kind = 'text';
        $parsed->moduleId = $this->id();
        $parsed->blocks = $blocks;
        if ($warning !== null) {
            $parsed->warnings[] = $warning;
        }

        return $parsed;
    }

    /** @return array<int,string> */
    private function fromRtf(string $path): array
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            return array();
        }
        $text = Encoding::toUtf8($raw);
        $text = (string) preg_replace('/\\\\par[d]?/', "\n", $text);
        $text = (string) preg_replace('/\\\\\'[0-9a-fA-F]{2}/', ' ', $text);
        $text = (string) preg_replace('/\\\\[a-z]+-?\d* ?/i', '', $text);
        $text = str_replace(array('{', '}'), '', $text);
        $lines = preg_split("/\n+/", $text);
        if ($lines === false) {
            return array();
        }
        $blocks = array();
        foreach ($lines as $line) {
            $line = trim((string) preg_replace('/\s+/u', ' ', $line));
            if ($line !== '') {
                $blocks[] = $line;
            }
        }

        return $blocks;
    }

    /** @return array<int,string> */
    private function fromHtml(string $path): array
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            return array();
        }
        $html = Encoding::toUtf8($raw);
        $html = (string) preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', ' ', $html);
        $html = (string) preg_replace('/<br\s*\/?>/i', "\n", $html);
        $html = (string) preg_replace('/<\/p>/i', "\n", $html);
        $text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $lines = preg_split("/\n+/", $text);
        if ($lines === false) {
            return array();
        }
        $blocks = array();
        foreach ($lines as $line) {
            $line = trim((string) preg_replace('/\s+/u', ' ', $line));
            if ($line !== '') {
                $blocks[] = $line;
            }
        }

        return $blocks;
    }

    /** @return array<int,string> */
    private function fromBinary(string $path): array
    {
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return array();
        }
        $text = @iconv('UTF-16LE', 'UTF-8//IGNORE', $raw);
        if ($text === false) {
            return array();
        }
        $parts = preg_split('/[^\p{L}\p{N}\p{P}\p{Z}]+/u', $text);
        if ($parts === false) {
            return array();
        }
        $blocks = array();
        foreach ($parts as $part) {
            $part = trim((string) preg_replace('/\s+/u', ' ', $part));
            if (mb_strlen($part, 'UTF-8') >= 8 && preg_match('/\p{L}/u', $part) === 1) {
                $blocks[] = $part;
            }
            if (count($blocks) >= 5000) {
                break;
            }
        }

        return $blocks;
    }
}
