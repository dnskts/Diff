<?php

declare(strict_types=1);

namespace Diff\Report;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ExcelReportWriter
{
    /** @param array<string,mixed> $state */
    public function write(string $path, array $state, string $diffPath): void
    {
        $spreadsheet = new Spreadsheet();
        $summary = $spreadsheet->getActiveSheet();
        $summary->setTitle('Сводка');
        $this->writeSummary($summary, $state);
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Отличия');
        $headers = array('Статус', 'Лист', 'Ключ', 'Поле', 'Было', 'Стало');
        foreach ($headers as $index => $header) {
            $sheet->setCellValueByColumnAndRow($index + 1, 1, $header);
        }
        $sheet->getStyle('A1:F1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle('A1:F1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('525C69');
        $row = 2;
        $written = 0;
        $limit = 50000;
        $handle = fopen($diffPath, 'rb');
        if ($handle !== false) {
            while (($line = fgets($handle)) !== false) {
                $item = json_decode($line, true);
                if (!is_array($item)) {
                    continue;
                }
                $written++;
                if ($written > $limit) {
                    break;
                }
                $values = array(
                    $this->label((string) $item['status']),
                    (string) $item['sheet'],
                    (string) $item['key'],
                    (string) $item['column'],
                    (string) $item['before'],
                    (string) $item['after'],
                );
                foreach ($values as $index => $value) {
                    $sheet->setCellValueExplicitByColumnAndRow($index + 1, $row, $value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                }
                $color = $this->color((string) $item['status']);
                if ($color !== null) {
                    $sheet->getStyle('A' . $row . ':F' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($color);
                }
                $row++;
            }
            fclose($handle);
        }
        if ($written === 0) {
            $sheet->setCellValue('A2', 'Отличий нет');
        }
        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:F1');
        $widths = array(16, 24, 28, 24, 42, 42);
        foreach ($widths as $index => $width) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index + 1))->setWidth($width);
        }
        $writer = new Xlsx($spreadsheet);
        $writer->save($path);
        $spreadsheet->disconnectWorksheets();
    }

    /** @param array<string,mixed> $state */
    private function writeSummary(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, array $state): void
    {
        $rows = array(
            array('Файл «было»', (string) $state['fileA']['name']),
            array('Файл «стало»', (string) $state['fileB']['name']),
            array('Дата', date('Y-m-d H:i:s')),
            array('Добавлено', (string) $state['summary']['added']),
            array('Удалено', (string) $state['summary']['removed']),
            array('Изменено', (string) $state['summary']['changed']),
            array('Без изменений', (string) $state['summary']['unchanged']),
            array('Конфликты ключа', (string) $state['summary']['conflicts']),
        );
        foreach ($rows as $index => $row) {
            $sheet->setCellValue('A' . ($index + 1), $row[0]);
            $sheet->setCellValueExplicit('B' . ($index + 1), $row[1], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        }
        $sheet->getStyle('A1:A8')->getFont()->setBold(true);
        $sheet->getColumnDimension('A')->setWidth(24);
        $sheet->getColumnDimension('B')->setWidth(64);
        $warnings = isset($state['warnings']) && is_array($state['warnings']) ? $state['warnings'] : array();
        $sheet->setCellValue('A10', 'Замечания');
        $sheet->getStyle('A10')->getFont()->setBold(true);
        if (count($warnings) === 0) {
            $sheet->setCellValue('A11', 'Нет');

            return;
        }
        foreach (array_slice($warnings, 0, 50) as $index => $warning) {
            $sheet->setCellValue('A' . (11 + $index), (string) $warning);
        }
    }

    private function label(string $status): string
    {
        $map = array(
            'added' => 'Добавлено',
            'removed' => 'Удалено',
            'changed' => 'Изменено',
            'conflict' => 'Конфликт ключа',
        );

        return isset($map[$status]) ? $map[$status] : $status;
    }

    private function color(string $status): ?string
    {
        $map = array(
            'added' => 'E5F6C8',
            'removed' => 'FFE8E6',
            'changed' => 'FFF4D6',
            'conflict' => 'F3E8FF',
        );

        return isset($map[$status]) ? $map[$status] : null;
    }
}
