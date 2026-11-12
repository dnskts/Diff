<?php

declare(strict_types=1);

namespace Diff\Modules\Text;

use Diff\AppException;
use Diff\Detect\FileProbe;
use Diff\Modules\Contract\FormatModule;
use Diff\Modules\Contract\ParsedContent;

class DocxModule implements FormatModule
{
    public function id(): string
    {
        return 'docx';
    }

    public function contentKind(): string
    {
        return 'text';
    }

    public function supports(FileProbe $probe): bool
    {
        return $probe->isZip() && $this->zipContains($probe->path, 'word/document.xml');
    }

    public function parse(string $path): ParsedContent
    {
        $blocks = $this->blocksFromZip($path);
        if (count($blocks) === 0) {
            throw new AppException('В DOCX не найден текст.');
        }
        $parsed = new ParsedContent();
        $parsed->kind = 'text';
        $parsed->moduleId = $this->id();
        $parsed->blocks = $blocks;

        return $parsed;
    }

    /** @return array<int,string> */
    private function blocksFromZip(string $path): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new AppException('Не удалось открыть DOCX.');
        }
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        if ($xml === false || $xml === '') {
            throw new AppException('В DOCX нет word/document.xml.');
        }

        $dom = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $ok = $dom->loadXML($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$ok) {
            throw new AppException('Не удалось разобрать document.xml в DOCX.');
        }

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $blocks = array();

        foreach ($xpath->query('//w:body/w:p') as $paragraph) {
            $line = $this->nodeText($xpath, $paragraph);
            if ($line !== '') {
                $blocks[] = $line;
            }
        }

        foreach ($xpath->query('//w:body/w:tbl/w:tr') as $row) {
            $cells = array();
            foreach ($xpath->query('./w:tc', $row) as $cell) {
                $cellText = $this->nodeText($xpath, $cell);
                if ($cellText !== '') {
                    $cells[] = $cellText;
                }
            }
            if (count($cells) > 0) {
                $blocks[] = implode(' | ', $cells);
            }
        }

        return $blocks;
    }

    private function nodeText(\DOMXPath $xpath, \DOMNode $node): string
    {
        $parts = array();
        foreach ($xpath->query('.//w:t', $node) as $textNode) {
            $parts[] = $textNode->textContent;
        }
        $line = trim((string) preg_replace('/\s+/u', ' ', implode('', $parts)));

        return $line;
    }

    private function zipContains(string $path, string $name): bool
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return false;
        }
        $found = $zip->locateName($name) !== false;
        $zip->close();

        return $found;
    }
}
