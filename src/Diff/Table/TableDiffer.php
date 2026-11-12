<?php

declare(strict_types=1);

namespace Diff\Diff\Table;

use Diff\Normalize\ExcelValueNormalizer;
use Diff\Support\Strings;

class TableDiffer
{
    /**
     * @param array{name:string,headers:array<int,string>,rows:array<int,array<int,string>>} $sheetA
     * @param array{name:string,headers:array<int,string>,rows:array<int,array<int,string>>} $sheetB
     * @param array<int,int> $keyColumns
     * @return array{items:array<int,array<string,mixed>>,warnings:array<int,string>,align:array<string,mixed>}
     */
    public function buildPlan(array $sheetA, array $sheetB, array $keyColumns, string $sheetLabel): array
    {
        $align = $this->align($sheetA['headers'], $sheetB['headers']);
        $warnings = $this->columnWarnings($sheetLabel, $align);
        if (count($keyColumns) === 0) {
            $items = $this->positionalItems($sheetA, $sheetB, $sheetLabel);
        } else {
            $built = $this->keyItems($sheetA, $sheetB, $keyColumns, $align, $sheetLabel);
            $items = $built['items'];
            $warnings = array_merge($warnings, $built['warnings']);
        }

        return array('items' => $items, 'warnings' => $warnings, 'align' => $align);
    }

    /**
     * @param array<string,mixed> $item
     * @param array{name:string,headers:array<int,string>,rows:array<int,array<int,string>>} $sheetA
     * @param array{name:string,headers:array<int,string>,rows:array<int,array<int,string>>} $sheetB
     * @param array<string,mixed> $align
     * @param array<int,string> $keyTitles названия ключевых колонок
     * @return array{lines:array<int,array<string,string>>,added:int,removed:int,changed:int,unchanged:int,conflicts:int}
     */
    public function expand(array $item, array $sheetA, array $sheetB, array $align, array $keyTitles = array(), bool $ignoreScientificNoise = true): array
    {
        $blank = array('lines' => array(), 'added' => 0, 'removed' => 0, 'changed' => 0, 'unchanged' => 0, 'conflicts' => 0);
        $op = (string) $item['op'];
        $sheet = (string) $item['sheet'];
        $key = (string) $item['key'];
        $keyTitle = $this->formatKeyTitle($keyTitles, $key);
        if ($op === 'onlyA') {
            $blank['removed'] = 1;
            $blank['lines'] = $this->fieldLines('removed', $sheet, $key, $keyTitle, $sheetA['headers'], $this->row($sheetA, $item['ia']), true);

            return $blank;
        }
        if ($op === 'onlyB') {
            $blank['added'] = 1;
            $blank['lines'] = $this->fieldLines('added', $sheet, $key, $keyTitle, $sheetB['headers'], $this->row($sheetB, $item['ib']), false);

            return $blank;
        }
        if ($op === 'conflict') {
            $blank['conflicts'] = 1;
            $side = (string) $item['side'];
            if ($side === 'A') {
                $blank['lines'] = $this->fieldLines('conflict', $sheet, $key, $keyTitle, $sheetA['headers'], $this->row($sheetA, $item['ia']), true);
            } elseif ($side === 'B') {
                $blank['lines'] = $this->fieldLines('conflict', $sheet, $key, $keyTitle, $sheetB['headers'], $this->row($sheetB, $item['ib']), false);
            } else {
                $blank['lines'][] = $this->line('conflict', $sheet, $key, $keyTitle, '(строка)', '', 'Ключ повторяется, строка не сопоставлена');
            }

            return $blank;
        }
        $rowA = $this->row($sheetA, $item['ia']);
        $rowB = $this->row($sheetB, $item['ib']);
        $changes = 0;
        foreach ($align['pairs'] as $pair) {
            $before = isset($rowA[$pair['ia']]) ? (string) $rowA[$pair['ia']] : '';
            $after = isset($rowB[$pair['ib']]) ? (string) $rowB[$pair['ib']] : '';
            if ($before === $after) {
                continue;
            }
            if ($ignoreScientificNoise && ExcelValueNormalizer::isLikelyExcelScientificNoise($before, $after)) {
                continue;
            }
            $changes++;
            $blank['lines'][] = $this->line('changed', $sheet, $key, $keyTitle, (string) $pair['header'], $before, $after);
        }
        if ($changes === 0) {
            $blank['unchanged'] = 1;
        } else {
            $blank['changed'] = 1;
        }

        return $blank;
    }

    /**
     * @param array<int,string> $headersA
     * @param array<int,string> $headersB
     * @return array{pairs:array<int,array{header:string,ia:int,ib:int}>,onlyA:array<int,string>,onlyB:array<int,string>,mapAtoB:array<int,int>}
     */
    public function align(array $headersA, array $headersB): array
    {
        $used = array();
        $pairs = array();
        $map = array();
        foreach ($headersA as $ia => $headerA) {
            $normA = $this->normHeader($headerA);
            foreach ($headersB as $ib => $headerB) {
                if (isset($used[$ib])) {
                    continue;
                }
                if ($normA !== '' && $normA === $this->normHeader($headerB)) {
                    $pairs[] = array('header' => $headerA, 'ia' => $ia, 'ib' => $ib);
                    $map[$ia] = $ib;
                    $used[$ib] = true;
                    continue 2;
                }
            }
        }
        $onlyA = array();
        foreach ($headersA as $ia => $headerA) {
            if (!isset($map[$ia])) {
                $onlyA[] = $headerA;
            }
        }
        $onlyB = array();
        foreach ($headersB as $ib => $headerB) {
            if (!isset($used[$ib])) {
                $onlyB[] = $headerB;
            }
        }

        return array('pairs' => $pairs, 'onlyA' => $onlyA, 'onlyB' => $onlyB, 'mapAtoB' => $map);
    }

    /**
     * @param array{name:string,headers:array<int,string>,rows:array<int,array<int,string>>} $sheetA
     * @param array{name:string,headers:array<int,string>,rows:array<int,array<int,string>>} $sheetB
     * @return array<int,array<string,mixed>>
     */
    private function positionalItems(array $sheetA, array $sheetB, string $sheetLabel): array
    {
        $max = max(count($sheetA['rows']), count($sheetB['rows']));
        $items = array();
        for ($i = 0; $i < $max; $i++) {
            $key = 'строка ' . ($i + 1);
            if ($i < count($sheetA['rows']) && $i < count($sheetB['rows'])) {
                $items[] = array('op' => 'match', 'sheet' => $sheetLabel, 'key' => $key, 'ia' => $i, 'ib' => $i);
            } elseif ($i < count($sheetA['rows'])) {
                $items[] = array('op' => 'onlyA', 'sheet' => $sheetLabel, 'key' => $key, 'ia' => $i, 'ib' => null);
            } else {
                $items[] = array('op' => 'onlyB', 'sheet' => $sheetLabel, 'key' => $key, 'ia' => null, 'ib' => $i);
            }
        }

        return $items;
    }

    /**
     * @param array{name:string,headers:array<int,string>,rows:array<int,array<int,string>>} $sheetA
     * @param array{name:string,headers:array<int,string>,rows:array<int,array<int,string>>} $sheetB
     * @param array<int,int> $keyColumns
     * @param array<string,mixed> $align
     * @return array{items:array<int,array<string,mixed>>,warnings:array<int,string>}
     */
    private function keyItems(array $sheetA, array $sheetB, array $keyColumns, array $align, string $sheetLabel): array
    {
        $warnings = array();
        $groupsA = $this->group($sheetA['rows'], $keyColumns, $align['mapAtoB'], false);
        $groupsB = $this->group($sheetB['rows'], $keyColumns, $align['mapAtoB'], true);
        $items = array();
        $dupWarned = 0;
        $keys = array_unique(array_merge(array_keys($groupsA), array_keys($groupsB)));
        foreach ($keys as $key) {
            $key = (string) $key;
            $left = isset($groupsA[$key]) ? $groupsA[$key] : array();
            $right = isset($groupsB[$key]) ? $groupsB[$key] : array();
            $display = $this->displayKey($key);
            if (count($left) > 1 || count($right) > 1) {
                if ($dupWarned < 20) {
                    $warnings[] = 'Лист «' . $sheetLabel . '»: повторяющийся ключ «' . $display . '». Эти строки не сопоставляются.';
                }
                $dupWarned++;
                foreach ($left as $index) {
                    $items[] = array('op' => 'conflict', 'sheet' => $sheetLabel, 'key' => $display, 'ia' => $index, 'ib' => null, 'side' => 'A');
                }
                foreach ($right as $index) {
                    $items[] = array('op' => 'conflict', 'sheet' => $sheetLabel, 'key' => $display, 'ia' => null, 'ib' => $index, 'side' => 'B');
                }
                continue;
            }
            if (count($left) === 1 && count($right) === 1) {
                $items[] = array('op' => 'match', 'sheet' => $sheetLabel, 'key' => $display, 'ia' => $left[0], 'ib' => $right[0]);
            } elseif (count($left) === 1) {
                $items[] = array('op' => 'onlyA', 'sheet' => $sheetLabel, 'key' => $display, 'ia' => $left[0], 'ib' => null);
            } else {
                $items[] = array('op' => 'onlyB', 'sheet' => $sheetLabel, 'key' => $display, 'ia' => null, 'ib' => $right[0]);
            }
        }
        if ($dupWarned > 20) {
            $warnings[] = 'Ещё повторяющихся ключей: ' . ($dupWarned - 20) . '.';
        }

        return array('items' => $items, 'warnings' => $warnings);
    }

    /**
     * @param array<int,array<int,string>> $rows
     * @param array<int,int> $keyColumns
     * @param array<int,int> $mapAtoB
     * @return array<string,array<int,int>>
     */
    private function group(array $rows, array $keyColumns, array $mapAtoB, bool $useMap): array
    {
        $groups = array();
        foreach ($rows as $index => $row) {
            $parts = array();
            foreach ($keyColumns as $column) {
                $resolved = $useMap && isset($mapAtoB[$column]) ? $mapAtoB[$column] : $column;
                $parts[] = isset($row[$resolved]) ? (string) $row[$resolved] : '';
            }
            $key = implode("\x1f", $parts);
            if (trim(str_replace("\x1f", '', $key)) === '') {
                $key = "\0empty:" . $index;
            }
            if (!isset($groups[$key])) {
                $groups[$key] = array();
            }
            $groups[$key][] = $index;
        }

        return $groups;
    }

    /** @param array<string,mixed> $align @return array<int,string> */
    private function columnWarnings(string $sheetLabel, array $align): array
    {
        $warnings = array();
        if (count($align['onlyA']) > 0) {
            $warnings[] = 'Лист «' . $sheetLabel . '»: колонки только в первом файле: ' . implode(', ', $align['onlyA']) . '.';
        }
        if (count($align['onlyB']) > 0) {
            $warnings[] = 'Лист «' . $sheetLabel . '»: колонки только во втором файле: ' . implode(', ', $align['onlyB']) . '.';
        }

        return $warnings;
    }

    private function normHeader(string $header): string
    {
        $header = str_replace("\xC2\xA0", ' ', Strings::lower($header));

        return trim((string) preg_replace('/\s+/u', ' ', $header));
    }

    private function displayKey(string $key): string
    {
        if (Strings::startsWith($key, "\0empty:")) {
            return '(пустой ключ)';
        }

        return str_replace("\x1f", ' | ', $key);
    }

    /**
     * @param array{headers:array<int,string>,rows:array<int,array<int,string>>} $sheet
     * @param mixed $index
     * @return array<int,string>
     */
    private function row(array $sheet, $index): array
    {
        if ($index === null || !isset($sheet['rows'][$index])) {
            return array();
        }

        return $sheet['rows'][$index];
    }

    /**
     * @param array<int,string> $headers
     * @param array<int,string> $row
     * @return array<int,array<string,string>>
     */
    private function fieldLines(string $status, string $sheet, string $key, string $keyTitle, array $headers, array $row, bool $asBefore): array
    {
        $lines = array();
        $n = max(count($headers), count($row));
        for ($i = 0; $i < $n; $i++) {
            $value = isset($row[$i]) ? (string) $row[$i] : '';
            if ($value === '') {
                continue;
            }
            $header = isset($headers[$i]) && $headers[$i] !== '' ? (string) $headers[$i] : ('Колонка ' . ($i + 1));
            $before = $asBefore ? $value : '';
            $after = $asBefore ? '' : $value;
            $lines[] = $this->line($status, $sheet, $key, $keyTitle, $header, $before, $after);
        }
        if (count($lines) === 0) {
            $lines[] = $this->line($status, $sheet, $key, $keyTitle, '(пустая строка)', $asBefore ? '—' : '', $asBefore ? '' : '—');
        }

        return $lines;
    }

    /** @param array<int,string> $titles */
    private function formatKeyTitle(array $titles, string $displayKey): string
    {
        if (count($titles) === 0) {
            return $displayKey;
        }
        if ($displayKey === '(пустой ключ)' || Strings::startsWith($displayKey, "\0empty:")) {
            return implode(' / ', $titles) . ' ' . $displayKey;
        }
        $values = explode(' | ', $displayKey);
        if (count($titles) === 1) {
            return trim($titles[0] . ' ' . $displayKey);
        }
        $parts = array();
        foreach ($titles as $i => $title) {
            $parts[] = trim($title . ' ' . (isset($values[$i]) ? $values[$i] : ''));
        }

        return implode(' · ', $parts);
    }

    /** @return array<string,string> */
    private function line(string $status, string $sheet, string $key, string $keyTitle, string $column, string $before, string $after): array
    {
        return array(
            'status' => $status,
            'sheet' => $sheet,
            'key' => $key,
            'keyTitle' => $keyTitle,
            'column' => $column,
            'before' => $before,
            'after' => $after,
        );
    }
}
