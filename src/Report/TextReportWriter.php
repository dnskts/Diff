<?php

declare(strict_types=1);

namespace Diff\Report;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class TextReportWriter
{
    /** @param array<string,mixed> $state */
    public function write(string $path, array $state, string $diffPath): void
    {
        $spreadsheet = new Spreadsheet();
        $summary = $spreadsheet->getActiveSheet();
        $summary->setTitle('Сводка');
        $summary->setCellValue('A1', 'Файл «было»');
        $summary->setCellValueExplicit('B1', (string) $state['fileA']['name'], DataType::TYPE_STRING);
        $summary->setCellValue('A2', 'Файл «стало»');
        $summary->setCellValueExplicit('B2', (string) $state['fileB']['name'], DataType::TYPE_STRING);
        $summary->setCellValue('A3', 'Добавлено');
        $summary->setCellValue('B3', (string) $state['summary']['added']);
        $summary->setCellValue('A4', 'Удалено');
        $summary->setCellValue('B4', (string) $state['summary']['removed']);
        $summary->setCellValue('A5', 'Изменено');
        $summary->setCellValue('B5', (string) $state['summary']['changed']);
        $summary->setCellValue('A6', 'Без изменений');
        $summary->setCellValue('B6', (string) $state['summary']['unchanged']);
        $summary->getStyle('A1:A6')->getFont()->setBold(true);
        $summary->getColumnDimension('A')->setWidth(24);
        $summary->getColumnDimension('B')->setWidth(64);

        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Текст');
        $sheet->setCellValue('A1', 'Статус');
        $sheet->setCellValue('B1', 'Было');
        $sheet->setCellValue('C1', 'Стало');
        $sheet->getStyle('A1:C1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle('A1:C1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('525C69');
        $row = 2;
        $written = 0;
        $handle = fopen($diffPath, 'rb');
        if ($handle !== false) {
            while (($line = fgets($handle)) !== false) {
                $item = json_decode($line, true);
                if (!is_array($item)) {
                    continue;
                }
                $written++;
                if ($written > 50000) {
                    break;
                }
                $status = (string) $item['status'];
                $labels = array('added' => 'Добавлено', 'removed' => 'Удалено', 'changed' => 'Изменено');
                $sheet->setCellValueExplicit('A' . $row, isset($labels[$status]) ? $labels[$status] : $status, DataType::TYPE_STRING);
                $sheet->setCellValueExplicit('B' . $row, (string) $item['before'], DataType::TYPE_STRING);
                $sheet->setCellValueExplicit('C' . $row, (string) $item['after'], DataType::TYPE_STRING);
                $colors = array('added' => 'E5F6C8', 'removed' => 'FFE8E6', 'changed' => 'FFF4D6');
                if (isset($colors[$status])) {
                    $sheet->getStyle('A' . $row . ':C' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($colors[$status]);
                }
                $row++;
            }
            fclose($handle);
        }
        if ($written === 0) {
            $sheet->setCellValue('A2', 'Отличий нет');
        }
        $sheet->getColumnDimension('A')->setWidth(16);
        $sheet->getColumnDimension('B')->setWidth(70);
        $sheet->getColumnDimension('C')->setWidth(70);
        $sheet->freezePane('A2');
        $writer = new Xlsx($spreadsheet);
        $writer->save($path);
        $spreadsheet->disconnectWorksheets();
    }
}
