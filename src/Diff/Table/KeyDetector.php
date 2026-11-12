<?php

declare(strict_types=1);

namespace Diff\Diff\Table;

use Diff\Support\Strings;

class KeyDetector
{
    /**
     * @param array<int,string> $headers
     * @param array<int,array<int,string>> $rowsA
     * @param array<int,array<int,string>> $rowsB
     * @return array{columns:array<int,int>,mode:string,confidence:string,reason:string}
     */
    public function suggest(array $headers, array $rowsA, array $rowsB): array
    {
        $count = count($headers);
        if ($count === 0 || count($rowsA) === 0) {
            return $this->positional('Нет строк, от которых можно выбрать ключ.');
        }
        $scores = array();
        for ($i = 0; $i < $count; $i++) {
            $scores[] = $this->score($i, $headers[$i], $rowsA, $rowsB);
        }
        usort($scores, static function (array $a, array $b): int {
            if ($a['total'] === $b['total']) {
                return 0;
            }

            return $a['total'] > $b['total'] ? -1 : 1;
        });
        $best = $scores[0];
        if ($best['uniqueness'] >= 0.9 && $best['fill'] >= 0.75) {
            $confidence = ($best['nameHit'] && $best['uniqueness'] >= 0.98) ? 'high' : 'medium';

            return array(
                'columns' => array($best['index']),
                'mode' => 'key',
                'confidence' => $confidence,
                'reason' => 'Ключ: «' . $headers[$best['index']] . '». Уникальность ' . $this->percent($best['uniqueness']) . '.',
            );
        }
        $pair = $this->bestPair($scores, $headers, $rowsA, $rowsB);
        if ($pair !== null) {
            return $pair;
        }

        return $this->positional('Не нашлась устойчивая колонка-ключ. Сравнение пойдёт по порядку строк, вставку строки лучше проверить вручную.');
    }

    /**
     * @param array<int,array<string,mixed>> $scores
     * @param array<int,string> $headers
     * @param array<int,array<int,string>> $rowsA
     * @param array<int,array<int,string>> $rowsB
     * @return array{columns:array<int,int>,mode:string,confidence:string,reason:string}|null
     */
    private function bestPair(array $scores, array $headers, array $rowsA, array $rowsB): ?array
    {
        $top = array_slice($scores, 0, 5);
        $best = null;
        $n = count($top);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $columns = array((int) $top[$i]['index'], (int) $top[$j]['index']);
                $stats = $this->combined($columns, $rowsA, $rowsB);
                if ($stats['uniqueness'] < 0.97 || $stats['fill'] < 0.75) {
                    continue;
                }
                if ($best === null || $stats['uniqueness'] > $best['uniqueness']) {
                    $best = $stats;
                    $best['columns'] = $columns;
                }
            }
        }
        if ($best === null) {
            return null;
        }
        $names = array($headers[$best['columns'][0]], $headers[$best['columns'][1]]);

        return array(
            'columns' => $best['columns'],
            'mode' => 'key',
            'confidence' => 'medium',
            'reason' => 'Составной ключ: «' . implode(' + ', $names) . '».',
        );
    }

    /**
     * @param array<int,string> $rowsSample ignored
     * @param array<int,array<int,string>> $rowsA
     * @param array<int,array<int,string>> $rowsB
     * @return array<string,mixed>
     */
    private function score(int $index, string $header, array $rowsA, array $rowsB): array
    {
        $statsA = $this->columnStats($index, $rowsA);
        $statsB = $this->columnStats($index, $rowsB);
        $overlap = $this->overlap($statsA['set'], $statsB['set']);
        $nameHit = $this->nameHit($header);
        $total = ($statsA['fill'] * 25) + ($statsA['uniqueness'] * 50) + ($overlap * 15) + ($nameHit ? 25 : 0);

        return array(
            'index' => $index,
            'fill' => $statsA['fill'],
            'uniqueness' => $statsA['uniqueness'],
            'total' => $total,
            'nameHit' => $nameHit,
        );
    }

    /**
     * @param array<int,int> $columns
     * @param array<int,array<int,string>> $rowsA
     * @param array<int,array<int,string>> $rowsB
     * @return array<string,float>
     */
    private function combined(array $columns, array $rowsA, array $rowsB): array
    {
        $values = array();
        $filled = 0;
        foreach ($rowsA as $row) {
            $parts = array();
            $empty = true;
            foreach ($columns as $index) {
                $value = isset($row[$index]) ? $row[$index] : '';
                if ($value !== '') {
                    $empty = false;
                }
                $parts[] = $value;
            }
            if ($empty) {
                continue;
            }
            $filled++;
            $values[] = implode("\x1f", $parts);
        }
        $total = count($rowsA);

        return array(
            'fill' => $total > 0 ? $filled / $total : 0,
            'uniqueness' => $filled > 0 ? count(array_unique($values)) / $filled : 0,
        );
    }

    /**
     * @param array<int,array<int,string>> $rows
     * @return array{fill:float,uniqueness:float,set:array<string,bool>}
     */
    private function columnStats(int $index, array $rows): array
    {
        $values = array();
        $set = array();
        $filled = 0;
        foreach ($rows as $row) {
            $value = isset($row[$index]) ? (string) $row[$index] : '';
            if ($value === '') {
                continue;
            }
            $filled++;
            $values[] = $value;
            $set[$value] = true;
        }
        $total = count($rows);

        return array(
            'fill' => $total > 0 ? $filled / $total : 0,
            'uniqueness' => $filled > 0 ? count(array_unique($values)) / $filled : 0,
            'set' => $set,
        );
    }

    /** @param array<string,bool> $a @param array<string,bool> $b */
    private function overlap(array $a, array $b): float
    {
        if (count($a) === 0 && count($b) === 0) {
            return 0;
        }
        $inter = 0;
        foreach ($a as $value => $_) {
            if (isset($b[$value])) {
                $inter++;
            }
        }
        $union = $inter;
        foreach ($a as $value => $_) {
            if (!isset($b[$value])) {
                $union++;
            }
        }
        foreach ($b as $value => $_) {
            if (!isset($a[$value])) {
                $union++;
            }
        }
        if ($union === 0) {
            return 0;
        }

        return $inter / $union;
    }

    private function nameHit(string $header): bool
    {
        $header = Strings::lower(trim($header));
        $needles = array('id', 'ид', 'код', 'артикул', 'sku', 'uuid', 'guid', 'номер', 'key', 'ключ', 'инн', 'табель');
        foreach ($needles as $needle) {
            $pattern = '/(^|[^\p{L}\p{N}])' . preg_quote($needle, '/') . '($|[^\p{L}\p{N}])/u';
            if (preg_match($pattern, $header) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @return array{columns:array<int,int>,mode:string,confidence:string,reason:string} */
    private function positional(string $reason): array
    {
        return array(
            'columns' => array(),
            'mode' => 'positional',
            'confidence' => 'low',
            'reason' => $reason,
        );
    }

    private function percent(float $value): string
    {
        return (string) (int) round($value * 100) . '%';
    }
}
