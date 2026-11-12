<?php

declare(strict_types=1);

namespace Diff\Support;

use Diff\AppException;

class JsonFile
{
    /** @return array<string,mixed>|array<int,mixed> */
    public static function read(string $path): array
    {
        if (!is_file($path)) {
            throw new AppException('Не найден файл задания.', 404);
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new AppException('Не удалось прочитать данные задания.');
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new AppException('Повреждены данные задания.');
        }

        return $data;
    }

    /** @param array<string,mixed>|array<int,mixed> $data */
    public static function write(string $path, array $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            throw new AppException('Не удалось сохранить данные задания.');
        }
        if (file_put_contents($path, $json, LOCK_EX) === false) {
            throw new AppException('Не удалось записать данные задания.');
        }
    }
}
