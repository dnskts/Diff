<?php

declare(strict_types=1);

namespace Diff\Modules\Table;

use Diff\AppException;
use Diff\Detect\FileProbe;
use Diff\Modules\Contract\FormatModule;
use Diff\Modules\Contract\ParsedContent;
use Diff\Normalize\ExcelValueNormalizer;
use Diff\Support\Encoding;

class CsvModule implements FormatModule
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
        return 'csv';
    }

    public function contentKind(): string
    {
        return 'table';
    }

    public function supports(FileProbe $probe): bool
    {
        return $probe->extension === 'csv' || $probe->extension === 'tsv';
    }

    public function parse(string $path): ParsedContent
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new AppException('Не удалось прочитать CSV.');
        }
        $text = Encoding::toUtf8($raw);
        $delimiter = strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'tsv'
            ? "\t"
            : Encoding::detectDelimiter($text);
        $handle = fopen('php://temp', 'rb+');
        if ($handle === false) {
            throw new AppException('Не удалось разобрать CSV.');
        }
        fwrite($handle, $text);
        rewind($handle);
        $matrix = array();
        $max = (int) $this->config['max_rows'] + 1;
        $scientific = false;
        while (($row = fgetcsv($handle, 1000000, $delimiter)) !== false) {
            if (count($matrix) >= $max) {
                fclose($handle);
                throw new AppException('Слишком много строк в CSV (лимит ' . $this->config['max_rows'] . ').');
            }
            $cells = array();
            foreach ($row as $cell) {
                $raw = (string) $cell;
                if (!$scientific && ExcelValueNormalizer::looksLikeScientificNotation($raw)) {
                    $scientific = true;
                }
                $cells[] = $this->normalizer->fromString($raw);
            }
            $matrix[] = $cells;
        }
        fclose($handle);
        $sheet = SheetMatrix::fromMatrix('CSV', $matrix, $this->normalizer);
        $parsed = new ParsedContent();
        $parsed->kind = 'table';
        $parsed->moduleId = $this->id();
        $parsed->meta = array(
            'delimiter' => $delimiter === "\t" ? 'tab' : $delimiter,
            'scientificNotation' => $scientific,
        );
        if ($sheet === null) {
            $parsed->warnings[] = 'CSV не содержит данных.';
        } else {
            $parsed->sheets[] = $sheet;
        }

        return $parsed;
    }
}
