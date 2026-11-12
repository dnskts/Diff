<?php

declare(strict_types=1);

namespace Diff\Modules\Table;

use Diff\AppException;
use Diff\Detect\FileProbe;
use Diff\Modules\Contract\FormatModule;
use Diff\Modules\Contract\ParsedContent;
use Diff\Normalize\ExcelValueNormalizer;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class XlsxModule implements FormatModule
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
        return 'xlsx';
    }

    public function contentKind(): string
    {
        return 'table';
    }

    public function supports(FileProbe $probe): bool
    {
        if ($probe->isZip() && $this->zipContains($probe->path, 'xl/workbook.xml')) {
            return true;
        }

        return $probe->isOle() && $probe->extension === 'xls';
    }

    public function parse(string $path): ParsedContent
    {
        $previous = Date::getExcelCalendar();
        try {
            $spreadsheet = IOFactory::load($path);
        } catch (\Throwable $e) {
            Date::setExcelCalendar($previous);
            throw new AppException('Не удалось прочитать Excel: ' . $e->getMessage());
        }
        try {
            $parsed = $this->readBook($spreadsheet);
        } finally {
            $spreadsheet->disconnectWorksheets();
            Date::setExcelCalendar($previous);
        }

        return $parsed;
    }

    private function readBook(Spreadsheet $spreadsheet): ParsedContent
    {
        $parsed = new ParsedContent();
        $parsed->kind = 'table';
        $parsed->moduleId = $this->id();
        $max = (int) $this->config['max_rows'];
        foreach ($spreadsheet->getWorksheetIterator() as $worksheet) {
            $sheet = $this->readSheet($worksheet, $max);
            if ($sheet === null) {
                $parsed->warnings[] = 'Лист «' . $worksheet->getTitle() . '» пуст и пропущен.';
                continue;
            }
            $parsed->sheets[] = $sheet;
            $this->warnIfSparse($parsed, $sheet);
        }
        if (count($parsed->sheets) === 0) {
            throw new AppException('В книге Excel нет заполненных листов.');
        }

        return $parsed;
    }

    /** @return array{name:string,headers:array<int,string>,rows:array<int,array<int,string>>}|null */
    private function readSheet(Worksheet $worksheet, int $max): ?array
    {
        $highestRow = (int) $worksheet->getHighestDataRow();
        $highestColumn = Coordinate::columnIndexFromString($worksheet->getHighestDataColumn());
        if ($highestRow < 1 || $highestColumn < 1) {
            return null;
        }
        if ($highestRow - 1 > $max) {
            throw new AppException('Слишком много строк на листе «' . $worksheet->getTitle() . '» (лимит ' . $max . ').');
        }
        $matrix = array();
        for ($rowIndex = 1; $rowIndex <= $highestRow; $rowIndex++) {
            $row = array();
            for ($column = 1; $column <= $highestColumn; $column++) {
                $row[] = $this->normalizer->fromCell($worksheet->getCellByColumnAndRow($column, $rowIndex));
            }
            $matrix[] = $row;
        }

        return SheetMatrix::fromMatrix($worksheet->getTitle(), $matrix, $this->normalizer);
    }

    /** @param array{name:string,headers:array<int,string>,rows:array<int,array<int,string>>} $sheet */
    private function warnIfSparse(ParsedContent $parsed, array $sheet): void
    {
        $cells = 0;
        $empty = 0;
        foreach ($sheet['rows'] as $row) {
            foreach ($row as $value) {
                $cells++;
                if ($value === '') {
                    $empty++;
                }
            }
        }
        if ($cells > 20 && ($empty / $cells) > 0.55) {
            $parsed->warnings[] = 'Лист «' . $sheet['name'] . '» похож на свод или разреженную таблицу. Ключ может быть ненадёжным.';
        }
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
