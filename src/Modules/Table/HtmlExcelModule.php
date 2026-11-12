<?php

declare(strict_types=1);

namespace Diff\Modules\Table;

use Diff\AppException;
use Diff\Detect\FileProbe;
use Diff\Modules\Contract\FormatModule;
use Diff\Modules\Contract\ParsedContent;
use Diff\Normalize\ExcelValueNormalizer;
use Diff\Support\Encoding;

class HtmlExcelModule implements FormatModule
{
    /** @var ExcelValueNormalizer */
    private $normalizer;

    /** @var array<string,mixed> */
    private $config;

    /** @param array<string,mixed> $config */
    public function __construct(ExcelValueNormalizer $normalizer, array $config)
    {
        $this->normalizer = $normalizer;
        $this->config = $config;
    }

    public function id(): string
    {
        return 'html_excel';
    }

    public function contentKind(): string
    {
        return 'table';
    }

    public function supports(FileProbe $probe): bool
    {
        if ($probe->extension === 'html' || $probe->extension === 'htm') {
            return true;
        }

        return $probe->extension === 'xls' && !$probe->isOle() && !$probe->isZip() && $probe->looksLikeHtml();
    }

    public function parse(string $path): ParsedContent
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new AppException('Не удалось прочитать HTML.');
        }
        $html = Encoding::toUtf8($raw);
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($loaded === false) {
            throw new AppException('HTML-таблица не разобрана. Сохраните файл как XLSX или CSV.');
        }
        $parsed = new ParsedContent();
        $parsed->kind = 'table';
        $parsed->moduleId = $this->id();
        $tables = $dom->getElementsByTagName('table');
        if ($tables->length === 0 && $dom->getElementsByTagName('Row')->length > 0) {
            $this->readSpreadsheetMl($dom, $parsed);
        } else {
            $this->readHtmlTables($tables, $parsed);
        }
        if (count($parsed->sheets) === 0) {
            throw new AppException('В файле не найдена таблица. Сохраните файл как XLSX или CSV.');
        }

        return $parsed;
    }

    private function readHtmlTables(\DOMNodeList $tables, ParsedContent $parsed): void
    {
        $index = 0;
        foreach ($tables as $table) {
            if (!$table instanceof \DOMElement) {
                continue;
            }
            $index++;
            $matrix = array();
            foreach ($table->getElementsByTagName('tr') as $tr) {
                if (!$tr instanceof \DOMElement) {
                    continue;
                }
                $row = array();
                foreach ($tr->childNodes as $cell) {
                    if (!$cell instanceof \DOMElement) {
                        continue;
                    }
                    $tag = strtolower($cell->tagName);
                    if ($tag !== 'td' && $tag !== 'th') {
                        continue;
                    }
                    $span = (int) $cell->getAttribute('colspan');
                    if ($span < 1) {
                        $span = 1;
                    }
                    $text = $this->normalizer->fromString($this->cellText($cell));
                    $row[] = $text;
                    for ($i = 1; $i < $span; $i++) {
                        $row[] = '';
                    }
                    if ($cell->hasAttribute('rowspan') && (int) $cell->getAttribute('rowspan') > 1) {
                        $parsed->warnings[] = 'В HTML есть объединённые строки. Сравнение этой таблицы приблизительное.';
                    }
                }
                if (count($row) > 0) {
                    $matrix[] = $row;
                }
                if (count($matrix) > (int) $this->config['max_rows'] + 1) {
                    throw new AppException('Слишком много строк в HTML-таблице.');
                }
            }
            $name = 'Таблица ' . $index;
            $sheet = SheetMatrix::fromMatrix($name, $matrix, $this->normalizer);
            if ($sheet !== null) {
                $parsed->sheets[] = $sheet;
            }
        }
    }

    private function readSpreadsheetMl(\DOMDocument $dom, ParsedContent $parsed): void
    {
        $worksheets = $dom->getElementsByTagName('Worksheet');
        $index = 0;
        foreach ($worksheets as $worksheet) {
            if (!$worksheet instanceof \DOMElement) {
                continue;
            }
            $index++;
            $name = $worksheet->getAttribute('ss:Name');
            if ($name === '') {
                $name = 'Лист ' . $index;
            }
            $matrix = array();
            foreach ($worksheet->getElementsByTagName('Row') as $rowNode) {
                if (!$rowNode instanceof \DOMElement || $rowNode->parentNode === null) {
                    continue;
                }
                $row = array();
                foreach ($rowNode->getElementsByTagName('Cell') as $cell) {
                    if (!$cell instanceof \DOMElement) {
                        continue;
                    }
                    $data = '';
                    foreach ($cell->getElementsByTagName('Data') as $dataNode) {
                        $data = $this->normalizer->fromString($dataNode->textContent);
                        break;
                    }
                    $row[] = $data;
                }
                $matrix[] = $row;
            }
            $sheet = SheetMatrix::fromMatrix($name, $matrix, $this->normalizer);
            if ($sheet !== null) {
                $parsed->sheets[] = $sheet;
            }
        }
    }

    private function cellText(\DOMElement $cell): string
    {
        $text = str_replace("\xC2\xA0", ' ', $cell->textContent);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
