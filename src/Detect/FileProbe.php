<?php

declare(strict_types=1);

namespace Diff\Detect;

class FileProbe
{
    /** @var string */
    public $path;

    /** @var string */
    public $originalName;

    /** @var string */
    public $extension;

    /** @var string */
    public $head;

    public static function fromPath(string $path, string $originalName): self
    {
        $probe = new self();
        $probe->path = $path;
        $probe->originalName = $originalName;
        $probe->extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $handle = fopen($path, 'rb');
        $probe->head = $handle ? (string) fread($handle, 4096) : '';
        if ($handle) {
            fclose($handle);
        }

        return $probe;
    }

    public function isZip(): bool
    {
        return strncmp($this->head, "PK\x03\x04", 4) === 0;
    }

    public function isOle(): bool
    {
        return strncmp($this->head, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1", 8) === 0;
    }

    public function looksLikeHtml(): bool
    {
        $head = ltrim($this->head);
        if (stripos($head, '<html') !== false || stripos($head, '<table') !== false) {
            return true;
        }
        if (stripos($head, 'urn:schemas-microsoft-com:office:spreadsheet') !== false) {
            return true;
        }

        return stripos($head, 'urn:schemas-microsoft-com:office:excel') !== false;
    }

    public function looksLikeRtf(): bool
    {
        return strncmp(ltrim($this->head), '{\rtf', 5) === 0;
    }
}
