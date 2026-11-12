<?php

declare(strict_types=1);

namespace Diff\Normalize;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

/**
 * Канон значений для сравнения: числа, проценты, даты, текстовые коды.
 */
class ExcelValueNormalizer
{
    /** @var int */
    private $decimals;

    public function __construct(int $decimals = 10)
    {
        $this->decimals = $decimals;
    }

    public function fromCell(Cell $cell): string
    {
        $format = (string) $cell->getStyle()->getNumberFormat()->getFormatCode();
        $type = $cell->getDataType();
        if ($type === DataType::TYPE_FORMULA) {
            try {
                $value = $cell->getCalculatedValue();
            } catch (\Throwable $e) {
                $value = $cell->getOldCalculatedValue();
            }
            $type = $this->detectType($value);

            return $this->fromExcel($value, $type, $format);
        }

        return $this->fromExcel($cell->getValue(), $type, $format);
    }

    /** @param mixed $value */
    public function fromExcel($value, string $dataType, string $formatCode): string
    {
        if ($value instanceof RichText) {
            $value = $value->getPlainText();
        }
        if ($value === null) {
            return '';
        }
        if ($dataType === DataType::TYPE_ERROR) {
            return $this->cleanSpaces((string) $value);
        }
        if ($dataType === DataType::TYPE_BOOL || is_bool($value)) {
            return $value ? 'TRUE' : 'FALSE';
        }

        $formatCode = $formatCode === '' ? NumberFormat::FORMAT_GENERAL : $formatCode;
        $isDate = Date::isDateTimeFormatCode($formatCode);
        $isPercent = $this->isPercentFormat($formatCode);

        if (is_string($value)) {
            return $this->fromString($value, $isDate, $isPercent);
        }
        if (is_int($value) || is_float($value)) {
            $number = (float) $value;
            if ($isDate) {
                return $this->fromExcelSerial($number, $formatCode);
            }
            if ($isPercent) {
                return $this->canonNumber($number);
            }

            return $this->canonNumber($number);
        }

        return $this->fromString((string) $value, $isDate, $isPercent);
    }

    public function fromString(string $value, bool $preferDate = false, bool $preferPercent = false): string
    {
        $value = $this->cleanSpaces($value);
        if ($value === '') {
            return '';
        }
        $bool = $this->boolToken($value);
        if ($bool !== null) {
            return $bool;
        }
        if ($value[0] === '#') {
            return $value;
        }
        if (preg_match('/^0\d+$/', $value) === 1) {
            return $value;
        }
        if (preg_match('/^[+-]?\d+(?:[.,]\d+)?\s*%$/', $value) === 1) {
            $number = $this->parseLocalizedNumber(rtrim($value, " \t%"));

            return $number === null ? $value : $this->canonNumber($number / 100);
        }
        $date = $this->parseDateString($value);
        if ($date !== null) {
            return $date;
        }
        if ($preferDate && is_numeric($value)) {
            return $this->fromExcelSerial((float) $value, 'yyyy-mm-dd');
        }
        if (preg_match('/^[+-]?\d+(?:[.,]\d+)?(?:[eE][+-]?\d+)?$/', str_replace(array(' ', "\xC2\xA0"), '', $value)) === 1
            || $this->looksLikeGroupedNumber($value)
        ) {
            $number = $this->parseLocalizedNumber($value);
            if ($number !== null) {
                if ($preferPercent) {
                    return $this->canonNumber($number);
                }

                return $this->canonNumber($number);
            }
        }

        return $value;
    }

    public function header(string $value): string
    {
        $value = str_replace("\xC2\xA0", ' ', $value);
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        return $value;
    }

    /** @param mixed $value */
    private function detectType($value): string
    {
        if (is_bool($value)) {
            return DataType::TYPE_BOOL;
        }
        if (is_int($value) || is_float($value)) {
            return DataType::TYPE_NUMERIC;
        }

        return DataType::TYPE_STRING;
    }

    private function fromExcelSerial(float $serial, string $formatCode): string
    {
        if (!is_finite($serial)) {
            return '';
        }
        try {
            $date = Date::excelToDateTimeObject($serial);
        } catch (\Throwable $e) {
            return $this->canonNumber($serial);
        }
        $hasTime = $this->formatHasTime($formatCode) || abs($serial - floor($serial)) > 0.0000001;
        $hasDate = $this->formatHasDate($formatCode) || $serial >= 1;
        if ($hasTime && !$hasDate) {
            return $date->format('H:i:s');
        }
        if ($hasTime) {
            return $date->format('Y-m-d H:i:s');
        }

        return $date->format('Y-m-d');
    }

    private function canonNumber(float $number): string
    {
        if (!is_finite($number)) {
            return '';
        }
        $rounded = round($number, $this->decimals);
        if (abs($rounded) < pow(10, -$this->decimals) / 2) {
            return '0';
        }
        if (abs($rounded - round($rounded)) < pow(10, -($this->decimals - 1))) {
            return sprintf('%.0F', round($rounded));
        }
        $text = sprintf('%.'.$this->decimals.'F', $rounded);
        $text = rtrim(rtrim($text, '0'), '.');

        return $text === '' || $text === '-' ? '0' : $text;
    }

    public static function looksLikeScientificNotation(string $value): bool
    {
        $value = str_replace(array(' ', "\xC2\xA0"), '', trim($value));

        return preg_match('/^[+-]?\d+[.,]\d+[eE][+-]?\d+$/', $value) === 1;
    }

    /**
     * Ложные отличия длинных ID после Excel CSV (1,23E+11 vs 1,23123E+11).
     */
    public static function isLikelyExcelScientificNoise(string $left, string $right): bool
    {
        if ($left === $right) {
            return false;
        }
        if (preg_match('/^[+-]?\d+$/', $left) !== 1 || preg_match('/^[+-]?\d+$/', $right) !== 1) {
            return false;
        }
        $digitsLeft = ltrim($left, '+-');
        $digitsRight = ltrim($right, '+-');
        if (strlen($digitsLeft) < 10 || strlen($digitsRight) < 10) {
            return false;
        }
        $fa = (float) $left;
        $fb = (float) $right;
        if (!is_finite($fa) || !is_finite($fb) || $fa == 0.0 || $fb == 0.0) {
            return false;
        }

        // 3 значащих цифры — типичная грубая научная запись Excel в CSV.
        return sprintf('%.2e', $fa) === sprintf('%.2e', $fb);
    }

    private function parseLocalizedNumber(string $value): ?float
    {
        $value = str_replace(array("\xC2\xA0", ' '), '', trim($value));
        if ($value === '' || $value === '+' || $value === '-') {
            return null;
        }
        $hasComma = strpos($value, ',') !== false;
        $hasDot = strpos($value, '.') !== false;
        if ($hasComma && $hasDot) {
            $lastComma = strrpos($value, ',');
            $lastDot = strrpos($value, '.');
            if ($lastComma > $lastDot) {
                $value = str_replace('.', '', $value);
                $value = str_replace(',', '.', $value);
            } else {
                $value = str_replace(',', '', $value);
            }
        } elseif ($hasComma) {
            if (preg_match('/^[+-]?\d{1,3}(,\d{3})+$/', $value) === 1) {
                $value = str_replace(',', '', $value);
            } else {
                $value = str_replace(',', '.', $value);
            }
        }
        if (!is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private function looksLikeGroupedNumber(string $value): bool
    {
        return preg_match('/^[+-]?\d{1,3}(?:[ \x{00A0}.]\d{3})+(?:,\d+)?$/u', $value) === 1
            || preg_match('/^[+-]?\d{1,3}(?:,\d{3})+(?:\.\d+)?$/', $value) === 1;
    }

    private function parseDateString(string $value): ?string
    {
        $patterns = array(
            '/^(\d{4})-(\d{2})-(\d{2})$/' => 'ymd',
            '/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?$/' => 'ymdhis',
            '/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/' => 'dmy',
            '/^(\d{1,2})\.(\d{1,2})\.(\d{4})[ T](\d{2}):(\d{2})(?::(\d{2}))?$/' => 'dmyhis',
            '/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/' => 'dmy',
            '/^(\d{1,2})\/(\d{1,2})\/(\d{4})[ T](\d{2}):(\d{2})(?::(\d{2}))?$/' => 'dmyhis',
        );
        foreach ($patterns as $pattern => $kind) {
            if (preg_match($pattern, $value, $m) !== 1) {
                continue;
            }
            if ($kind === 'ymd') {
                return $this->isoDate((int) $m[1], (int) $m[2], (int) $m[3], 0, 0, 0, false);
            }
            if ($kind === 'ymdhis') {
                return $this->isoDate((int) $m[1], (int) $m[2], (int) $m[3], (int) $m[4], (int) $m[5], isset($m[6]) ? (int) $m[6] : 0, true);
            }
            if ($kind === 'dmy') {
                return $this->isoDate((int) $m[3], (int) $m[2], (int) $m[1], 0, 0, 0, false);
            }

            return $this->isoDate((int) $m[3], (int) $m[2], (int) $m[1], (int) $m[4], (int) $m[5], isset($m[6]) ? (int) $m[6] : 0, true);
        }

        return null;
    }

    private function isoDate(int $y, int $mo, int $d, int $h, int $i, int $s, bool $withTime): ?string
    {
        if (!checkdate($mo, $d, $y) || $h > 23 || $i > 59 || $s > 59) {
            return null;
        }
        $stamp = sprintf('%04d-%02d-%02d', $y, $mo, $d);
        if (!$withTime) {
            return $stamp;
        }

        return $stamp . sprintf(' %02d:%02d:%02d', $h, $i, $s);
    }

    private function boolToken(string $value): ?string
    {
        $token = mb_strtolower($value, 'UTF-8');
        if ($token === 'true' || $token === 'истина') {
            return 'TRUE';
        }
        if ($token === 'false' || $token === 'ложь') {
            return 'FALSE';
        }

        return null;
    }

    private function cleanSpaces(string $value): string
    {
        $value = str_replace("\xC2\xA0", ' ', $value);

        return trim($value);
    }

    private function isPercentFormat(string $formatCode): bool
    {
        $plain = $this->stripFormatLiterals($formatCode);

        return strpos($plain, '%') !== false;
    }

    private function formatHasTime(string $formatCode): bool
    {
        $plain = strtolower($this->stripFormatLiterals($formatCode));

        return strpos($plain, 'h') !== false || strpos($plain, 's') !== false;
    }

    private function formatHasDate(string $formatCode): bool
    {
        $plain = strtolower($this->stripFormatLiterals($formatCode));

        return strpos($plain, 'y') !== false || strpos($plain, 'd') !== false;
    }

    private function stripFormatLiterals(string $formatCode): string
    {
        $formatCode = (string) preg_replace('/\[[^\]]*\]/', '', $formatCode);
        $formatCode = (string) preg_replace('/"[^"]*"/', '', $formatCode);

        return $formatCode;
    }
}
