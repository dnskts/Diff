<?php

declare(strict_types=1);

namespace Diff\Support;

class Encoding
{
    public static function toUtf8(string $raw): string
    {
        if (Strings::startsWith($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }
        if (Strings::startsWith($raw, "\xFF\xFE")) {
            $text = @iconv('UTF-16LE', 'UTF-8//IGNORE', substr($raw, 2));

            return $text === false ? '' : $text;
        }
        if (Strings::startsWith($raw, "\xFE\xFF")) {
            $text = @iconv('UTF-16BE', 'UTF-8//IGNORE', substr($raw, 2));

            return $text === false ? '' : $text;
        }
        if (self::isUtf8($raw)) {
            return $raw;
        }
        $text = @iconv('Windows-1251', 'UTF-8//IGNORE', $raw);

        return $text === false ? $raw : $text;
    }

    public static function isUtf8(string $raw): bool
    {
        return mb_check_encoding($raw, 'UTF-8');
    }

    public static function detectDelimiter(string $sample): string
    {
        $sample = substr($sample, 0, 8192);
        $counts = array(',' => substr_count($sample, ','), ';' => substr_count($sample, ';'), "\t" => substr_count($sample, "\t"));
        arsort($counts);
        $best = (string) key($counts);
        if ($counts[$best] === 0) {
            return ';';
        }

        return $best;
    }
}
