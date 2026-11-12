<?php

declare(strict_types=1);

namespace Diff\Diff\Text;

class TextDiffer
{
    private const LCS_CELL_LIMIT = 250000;

    /**
     * @param array<int,string> $before
     * @param array<int,string> $after
     * @return array{ops:array<int,array<string,string>>,lowConfidence:bool}
     */
    public function diff(array $before, array $after): array
    {
        $context = array('fallback' => false);
        $ops = $this->diffSlice($before, $after, 0, count($before), 0, count($after), 0, $context);
        $ops = $this->coalesce($ops);

        return array('ops' => $ops, 'lowConfidence' => $context['fallback']);
    }

    /**
     * @param array<int,string> $a
     * @param array<int,string> $b
     * @param array{fallback:bool} $context
     * @return array<int,array<string,string>>
     */
    private function diffSlice(array $a, array $b, int $a0, int $a1, int $b0, int $b1, int $depth, array &$context): array
    {
        $prefix = array();
        while ($a0 < $a1 && $b0 < $b1 && $a[$a0] === $b[$b0]) {
            $prefix[] = $this->op('equal', $a[$a0], $b[$b0]);
            $a0++;
            $b0++;
        }
        $sufA = $a1;
        $sufB = $b1;
        while ($a0 < $sufA && $b0 < $sufB && $a[$sufA - 1] === $b[$sufB - 1]) {
            $sufA--;
            $sufB--;
        }
        $middle = $this->diffMiddle($a, $b, $a0, $sufA, $b0, $sufB, $depth, $context);
        $suffix = array();
        for ($i = $sufA, $j = $sufB; $i < $a1 && $j < $b1; $i++, $j++) {
            $suffix[] = $this->op('equal', $a[$i], $b[$j]);
        }

        return array_merge($prefix, $middle, $suffix);
    }

    /**
     * @param array<int,string> $a
     * @param array<int,string> $b
     * @param array{fallback:bool} $context
     * @return array<int,array<string,string>>
     */
    private function diffMiddle(array $a, array $b, int $a0, int $a1, int $b0, int $b1, int $depth, array &$context): array
    {
        if ($a0 >= $a1 && $b0 >= $b1) {
            return array();
        }
        if ($a0 >= $a1) {
            return $this->run('added', $b, $b0, $b1);
        }
        if ($b0 >= $b1) {
            return $this->run('removed', $a, $a0, $a1);
        }
        if ($depth > 40) {
            $context['fallback'] = true;

            return $this->positional($a, $b, $a0, $a1, $b0, $b1);
        }
        $anchors = $this->anchors($a, $b, $a0, $a1, $b0, $b1);
        if (count($anchors) === 0) {
            $cells = ($a1 - $a0) * ($b1 - $b0);
            if ($cells <= self::LCS_CELL_LIMIT && ($a1 - $a0) <= 800 && ($b1 - $b0) <= 800) {
                return $this->lcs($a, $b, $a0, $a1, $b0, $b1);
            }
            $context['fallback'] = true;

            return $this->positional($a, $b, $a0, $a1, $b0, $b1);
        }
        $ops = array();
        $prevA = $a0;
        $prevB = $b0;
        foreach ($anchors as $anchor) {
            $ops = array_merge($ops, $this->diffSlice($a, $b, $prevA, $anchor[0], $prevB, $anchor[1], $depth + 1, $context));
            $ops[] = $this->op('equal', $a[$anchor[0]], $b[$anchor[1]]);
            $prevA = $anchor[0] + 1;
            $prevB = $anchor[1] + 1;
        }

        return array_merge($ops, $this->diffSlice($a, $b, $prevA, $a1, $prevB, $b1, $depth + 1, $context));
    }

    /**
     * @param array<int,string> $a
     * @param array<int,string> $b
     * @return array<int,array{0:int,1:int}>
     */
    private function anchors(array $a, array $b, int $a0, int $a1, int $b0, int $b1): array
    {
        $countA = array();
        for ($i = $a0; $i < $a1; $i++) {
            $line = $a[$i];
            if (!isset($countA[$line])) {
                $countA[$line] = array();
            }
            if (count($countA[$line]) < 2) {
                $countA[$line][] = $i;
            } else {
                $countA[$line][1] = -1;
            }
        }
        $countB = array();
        for ($i = $b0; $i < $b1; $i++) {
            $line = $b[$i];
            if (!isset($countB[$line])) {
                $countB[$line] = array();
            }
            if (count($countB[$line]) < 2) {
                $countB[$line][] = $i;
            } else {
                $countB[$line][1] = -1;
            }
        }
        $pairs = array();
        foreach ($countA as $line => $positions) {
            if (count($positions) !== 1 || !isset($countB[$line]) || count($countB[$line]) !== 1) {
                continue;
            }
            $pairs[] = array($positions[0], $countB[$line][0]);
        }
        usort($pairs, static function (array $left, array $right): int {
            return $left[0] <=> $right[0];
        });

        return $this->lis($pairs);
    }

    /**
     * @param array<int,array{0:int,1:int}> $pairs
     * @return array<int,array{0:int,1:int}>
     */
    private function lis(array $pairs): array
    {
        $tails = array();
        $tailsIndex = array();
        $prev = array();
        foreach ($pairs as $i => $pair) {
            $value = $pair[1];
            $lo = 0;
            $hi = count($tails);
            while ($lo < $hi) {
                $mid = intdiv($lo + $hi, 2);
                if ($tails[$mid] < $value) {
                    $lo = $mid + 1;
                } else {
                    $hi = $mid;
                }
            }
            $prev[$i] = $lo > 0 ? $tailsIndex[$lo - 1] : -1;
            $tails[$lo] = $value;
            $tailsIndex[$lo] = $i;
        }
        if (count($tailsIndex) === 0) {
            return array();
        }
        $cursor = $tailsIndex[count($tailsIndex) - 1];
        $seq = array();
        while ($cursor >= 0) {
            $seq[] = $pairs[$cursor];
            $cursor = $prev[$cursor];
        }

        return array_reverse($seq);
    }

    /**
     * @param array<int,string> $a
     * @param array<int,string> $b
     * @return array<int,array<string,string>>
     */
    private function lcs(array $a, array $b, int $a0, int $a1, int $b0, int $b1): array
    {
        $n = $a1 - $a0;
        $m = $b1 - $b0;
        $dp = array();
        for ($i = 0; $i <= $n; $i++) {
            $dp[$i] = array_fill(0, $m + 1, 0);
        }
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                if ($a[$a0 + $i] === $b[$b0 + $j]) {
                    $dp[$i][$j] = $dp[$i + 1][$j + 1] + 1;
                } else {
                    $dp[$i][$j] = $dp[$i + 1][$j] >= $dp[$i][$j + 1] ? $dp[$i + 1][$j] : $dp[$i][$j + 1];
                }
            }
        }
        $ops = array();
        $i = 0;
        $j = 0;
        while ($i < $n && $j < $m) {
            if ($a[$a0 + $i] === $b[$b0 + $j]) {
                $ops[] = $this->op('equal', $a[$a0 + $i], $b[$b0 + $j]);
                $i++;
                $j++;
            } elseif ($dp[$i + 1][$j] >= $dp[$i][$j + 1]) {
                $ops[] = $this->op('removed', $a[$a0 + $i], '');
                $i++;
            } else {
                $ops[] = $this->op('added', '', $b[$b0 + $j]);
                $j++;
            }
        }
        while ($i < $n) {
            $ops[] = $this->op('removed', $a[$a0 + $i], '');
            $i++;
        }
        while ($j < $m) {
            $ops[] = $this->op('added', '', $b[$b0 + $j]);
            $j++;
        }

        return $ops;
    }

    /**
     * @param array<int,string> $lines
     * @return array<int,array<string,string>>
     */
    private function run(string $status, array $lines, int $from, int $to): array
    {
        $ops = array();
        for ($i = $from; $i < $to; $i++) {
            if ($status === 'added') {
                $ops[] = $this->op('added', '', $lines[$i]);
            } else {
                $ops[] = $this->op('removed', $lines[$i], '');
            }
        }

        return $ops;
    }

    /**
     * @param array<int,string> $a
     * @param array<int,string> $b
     * @return array<int,array<string,string>>
     */
    private function positional(array $a, array $b, int $a0, int $a1, int $b0, int $b1): array
    {
        $ops = array();
        $n = max($a1 - $a0, $b1 - $b0);
        for ($i = 0; $i < $n; $i++) {
            $hasA = ($a0 + $i) < $a1;
            $hasB = ($b0 + $i) < $b1;
            if ($hasA && $hasB) {
                $left = $a[$a0 + $i];
                $right = $b[$b0 + $i];
                $ops[] = $left === $right ? $this->op('equal', $left, $right) : $this->op('changed', $left, $right);
            } elseif ($hasA) {
                $ops[] = $this->op('removed', $a[$a0 + $i], '');
            } else {
                $ops[] = $this->op('added', '', $b[$b0 + $i]);
            }
        }

        return $ops;
    }

    /**
     * @param array<int,array<string,string>> $ops
     * @return array<int,array<string,string>>
     */
    private function coalesce(array $ops): array
    {
        $out = array();
        $n = count($ops);
        for ($i = 0; $i < $n; $i++) {
            if ($ops[$i]['status'] !== 'removed') {
                $out[] = $ops[$i];
                continue;
            }
            $removed = array();
            while ($i < $n && $ops[$i]['status'] === 'removed') {
                $removed[] = $ops[$i];
                $i++;
            }
            $added = array();
            while ($i < $n && $ops[$i]['status'] === 'added') {
                $added[] = $ops[$i];
                $i++;
            }
            $i--;
            $pair = min(count($removed), count($added));
            for ($k = 0; $k < $pair; $k++) {
                $out[] = $this->op('changed', $removed[$k]['before'], $added[$k]['after']);
            }
            for ($k = $pair; $k < count($removed); $k++) {
                $out[] = $removed[$k];
            }
            for ($k = $pair; $k < count($added); $k++) {
                $out[] = $added[$k];
            }
        }

        return $out;
    }

    /** @return array<string,string> */
    private function op(string $status, string $before, string $after): array
    {
        return array(
            'status' => $status,
            'sheet' => 'Текст',
            'key' => '',
            'column' => '',
            'before' => $before,
            'after' => $after,
        );
    }
}
