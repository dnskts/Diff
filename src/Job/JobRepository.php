<?php

declare(strict_types=1);

namespace Diff\Job;

use Diff\AppException;
use Diff\Support\JsonFile;

class JobRepository
{
    /** @var string */
    private $root;

    /** @var int */
    private $ttlHours;

    public function __construct(string $root, int $ttlHours)
    {
        $this->root = $root;
        $this->ttlHours = $ttlHours;
        if (!is_dir($this->root) && !mkdir($this->root, 0775, true) && !is_dir($this->root)) {
            throw new AppException('Не удалось создать каталог заданий.');
        }
    }

    public function create(string $nameA, string $extA, string $nameB, string $extB): array
    {
        $id = bin2hex(random_bytes(16));
        $dir = $this->dir($id);
        if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new AppException('Не удалось создать задание.');
        }
        $state = array(
            'id' => $id,
            'status' => 'uploaded',
            'createdAt' => time(),
            'fileA' => array('name' => $nameA, 'stored' => 'source_a.' . $extA),
            'fileB' => array('name' => $nameB, 'stored' => 'source_b.' . $extB),
            'kind' => null,
            'modules' => array('a' => null, 'b' => null),
            'warnings' => array(),
            'summary' => $this->emptySummary(),
            'cursor' => 0,
            'total' => 0,
            'options' => array(),
            'prepare' => null,
            'reportName' => null,
        );
        JsonFile::write($dir . '/state.json', $state);

        return $state;
    }

    /** @return array<string,mixed> */
    public function read(string $id): array
    {
        $this->assertId($id);
        $state = JsonFile::read($this->dir($id) . '/state.json');
        if (!isset($state['id']) || $state['id'] !== $id) {
            throw new AppException('Задание не найдено.', 404);
        }

        return $state;
    }

    /** @param callable(array<string,mixed>):array<string,mixed> $mutator
     * @return array<string,mixed> */
    public function update(string $id, callable $mutator): array
    {
        $this->assertId($id);
        $lockPath = $this->dir($id) . '/state.lock';
        $lock = fopen($lockPath, 'c+');
        if ($lock === false) {
            throw new AppException('Не удалось заблокировать задание.');
        }
        flock($lock, LOCK_EX);
        try {
            $state = $this->read($id);
            $updated = $mutator($state);
            if (!is_array($updated)) {
                throw new AppException('Сбой состояния задания.');
            }
            JsonFile::write($this->dir($id) . '/state.json', $updated);

            return $updated;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function dir(string $id): string
    {
        $this->assertId($id);

        return $this->root . DIRECTORY_SEPARATOR . $id;
    }

    public function cleanupExpired(): void
    {
        $limit = time() - ($this->ttlHours * 3600);
        $items = glob($this->root . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR);
        if ($items === false) {
            return;
        }
        foreach ($items as $dir) {
            $statePath = $dir . DIRECTORY_SEPARATOR . 'state.json';
            $created = filemtime($dir);
            if (is_file($statePath)) {
                $raw = file_get_contents($statePath);
                $data = is_string($raw) ? json_decode($raw, true) : null;
                if (is_array($data) && isset($data['createdAt'])) {
                    $created = (int) $data['createdAt'];
                }
            }
            if ($created !== false && $created < $limit) {
                $this->removeTree($dir);
            }
        }
    }

    public function assertId(string $id): void
    {
        if (preg_match('/^[a-f0-9]{32}$/', $id) !== 1) {
            throw new AppException('Некорректный идентификатор задания.', 404);
        }
    }

    /** @return array<string,int> */
    public function emptySummary(): array
    {
        return array('added' => 0, 'removed' => 0, 'changed' => 0, 'unchanged' => 0, 'conflicts' => 0);
    }

    private function removeTree(string $dir): void
    {
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->removeTree($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
