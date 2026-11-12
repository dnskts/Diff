<?php

declare(strict_types=1);

namespace Diff\Modules\Table;

use Diff\Normalize\ExcelValueNormalizer;

class SheetMatrix
{
    /**
     * @param array<int,array<int,string>> $matrix
     * @return array{name:string,headers:array<int,string>,rows:array<int,array<int,string>>}|null
     */
    public static function fromMatrix(string $name, array $matrix, ExcelValueNormalizer $normalizer): ?array
    {
        $width = 0;
        foreach ($matrix as $row) {
            $width = max($width, count($row));
        }
        if ($width === 0 || count($matrix) === 0) {
            return null;
        }
        $last = count($matrix) - 1;
        while ($last > 0 && self::rowEmpty($matrix[$last], $width)) {
            $last--;
        }
        $matrix = array_slice($matrix, 0, $last + 1);
        $used = array();
        foreach ($matrix as $row) {
            for ($i = 0; $i < $width; $i++) {
                if (isset($row[$i]) && $row[$i] !== '') {
                    $used[$i] = true;
                }
            }
        }
        $keep = array();
        for ($i = 0; $i < $width; $i++) {
            if (isset($used[$i])) {
                $keep[] = $i;
            }
        }
        if (count($keep) === 0) {
            return null;
        }
        $headers = array();
        $headerRow = $matrix[0];
        foreach ($keep as $pos => $index) {
            $header = $normalizer->header(isset($headerRow[$index]) ? $headerRow[$index] : '');
            if ($header === '') {
                $header = 'Колонка ' . ($pos + 1);
            }
            $headers[] = $header;
        }
        $rows = array();
        for ($r = 1, $n = count($matrix); $r < $n; $r++) {
            $source = $matrix[$r];
            $row = array();
            $empty = true;
            foreach ($keep as $index) {
                $value = isset($source[$index]) ? $source[$index] : '';
                if ($value !== '') {
                    $empty = false;
                }
                $row[] = $value;
            }
            if ($empty && $r === $n - 1) {
                continue;
            }
            $rows[] = $row;
        }

        return array(
            'name' => $name,
            'headers' => $headers,
            'rows' => $rows,
        );
    }

    /** @param array<int,string> $row */
    private static function rowEmpty(array $row, int $width): bool
    {
        for ($i = 0; $i < $width; $i++) {
            if (isset($row[$i]) && $row[$i] !== '') {
                return false;
            }
        }

        return true;
    }
}
