<?php

declare(strict_types=1);

namespace Diff\Compare;

use Diff\AppException;
use Diff\Detect\FileProbe;
use Diff\Diff\Table\KeyDetector;
use Diff\Diff\Table\TableDiffer;
use Diff\Diff\Text\TextDiffer;
use Diff\Job\JobRepository;
use Diff\Modules\ModuleRegistry;
use Diff\Report\ExcelReportWriter;
use Diff\Report\HtmlReportWriter;
use Diff\Report\TextReportWriter;
use Diff\Support\JsonFile;
use Diff\Support\Strings;

class CompareService
{
    /** @var JobRepository */
    private $jobs;

    /** @var ModuleRegistry */
    private $modules;

    /** @var array<string,mixed> */
    private $config;

    /** @param array<string,mixed> $config */
    public function __construct(JobRepository $jobs, ModuleRegistry $modules, array $config)
    {
        $this->jobs = $jobs;
        $this->modules = $modules;
        $this->config = $config;
    }

    /** @param array<string,mixed> $fileA @param array<string,mixed> $fileB
     * @return array<string,mixed> */
    public function upload(array $fileA, array $fileB): array
    {
        $this->jobs->cleanupExpired();
        $this->assertUpload($fileA, 'первого');
        $this->assertUpload($fileB, 'второго');
        $nameA = $this->baseName((string) $fileA['name']);
        $nameB = $this->baseName((string) $fileB['name']);
        $extA = $this->extension($nameA);
        $extB = $this->extension($nameB);
        $state = $this->jobs->create($nameA, $extA, $nameB, $extB);
        $dir = $this->jobs->dir($state['id']);
        if (!move_uploaded_file((string) $fileA['tmp_name'], $dir . '/' . $state['fileA']['stored'])) {
            throw new AppException('Не удалось сохранить первый файл.');
        }
        if (!move_uploaded_file((string) $fileB['tmp_name'], $dir . '/' . $state['fileB']['stored'])) {
            throw new AppException('Не удалось сохранить второй файл.');
        }

        return $state;
    }

    /** @return array<string,mixed> */
    public function prepare(string $jobId): array
    {
        @set_time_limit(180);
        $state = $this->jobs->read($jobId);
        $dir = $this->jobs->dir($jobId);
        $parsedA = $this->parseSide($dir . '/' . $state['fileA']['stored'], (string) $state['fileA']['name']);
        $parsedB = $this->parseSide($dir . '/' . $state['fileB']['stored'], (string) $state['fileB']['name']);
        if ($parsedA['kind'] !== $parsedB['kind']) {
            throw new AppException('Нельзя сравнить таблицу с текстом. Загрузите два файла одного типа.');
        }
        $this->storeParsed($dir, 'a', $parsedA);
        $this->storeParsed($dir, 'b', $parsedB);
        $warnings = array_merge($parsedA['warnings'], $parsedB['warnings']);
        $prepare = $parsedA['kind'] === 'table'
            ? $this->prepareTable($parsedA, $parsedB, $warnings)
            : $this->prepareText($parsedA, $parsedB);
        $warnings = array_values(array_unique(array_merge($warnings, $prepare['warnings'])));
        unset($prepare['warnings']);

        return $this->jobs->update($jobId, function (array $state) use ($parsedA, $parsedB, $prepare, $warnings) {
            $state['status'] = 'prepared';
            $state['kind'] = $parsedA['kind'];
            $state['modules'] = array('a' => $parsedA['moduleId'], 'b' => $parsedB['moduleId']);
            $state['warnings'] = $warnings;
            $state['prepare'] = $prepare;
            $state['summary'] = $this->jobs->emptySummary();
            $state['cursor'] = 0;
            $state['total'] = 0;

            return $state;
        });
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function start(string $jobId, array $options): array
    {
        @set_time_limit(180);
        $state = $this->jobs->read($jobId);
        if (!isset($state['prepare']) || !is_array($state['prepare'])) {
            throw new AppException('Сначала нужно разобрать файлы.');
        }
        $dir = $this->jobs->dir($jobId);
        $this->resetOutputs($dir);
        if ($state['kind'] === 'text') {
            $built = $this->buildTextWork($dir, $options);
        } else {
            $built = $this->buildTableWork($dir, $state, $options);
        }
        $warnings = array_values(array_unique(array_merge($state['warnings'], $built['warnings'])));

        return $this->jobs->update($jobId, function (array $state) use ($options, $built, $warnings) {
            if ($state['status'] === 'cancelled') {
                return $state;
            }
            $state['status'] = 'running';
            $state['options'] = $options;
            $state['warnings'] = $warnings;
            $state['summary'] = $built['summary'];
            $state['cursor'] = 0;
            $state['total'] = $built['total'];
            $state['reportName'] = null;

            return $state;
        });
    }

    /** @return array<string,mixed> */
    public function process(string $jobId): array
    {
        @set_time_limit(120);
        $state = $this->jobs->read($jobId);
        if ($state['status'] === 'cancelled' || $state['status'] === 'done') {
            return $this->payload($state, array());
        }
        if ($state['status'] === 'finalizing') {
            $this->writeReport($state);
            $state = $this->jobs->update($jobId, function (array $state) {
                if ($state['status'] === 'cancelled') {
                    return $state;
                }
                $state['status'] = 'done';
                $state['reportName'] = $this->reportName($state);

                return $state;
            });

            return $this->payload($state, array());
        }
        if ($state['status'] !== 'running') {
            throw new AppException('Сравнение ещё не запущено.');
        }
        $dir = $this->jobs->dir($jobId);
        $cursor = (int) $state['cursor'];
        $total = (int) $state['total'];
        $limit = (int) $this->config['chunk_size'];
        $items = $this->readWork($dir . '/work.jsonl', $cursor, $limit);
        $lines = array();
        $delta = $this->jobs->emptySummary();
        if ($state['kind'] === 'text') {
            foreach ($items as $item) {
                $this->countLine($delta, (string) $item['status']);
                if ($item['status'] !== 'equal') {
                    $lines[] = $item;
                }
            }
        } else {
            $cache = array();
            $aligns = JsonFile::read($dir . '/align.json');
            $differ = new TableDiffer();
            foreach ($items as $item) {
                $sheetA = $this->cachedSheet($dir, 'a', (int) $item['aSheet'], $cache);
                $sheetB = $this->cachedSheet($dir, 'b', (int) $item['bSheet'], $cache);
                $key = (string) $item['sheetKey'];
                $keyTitles = isset($item['keyTitles']) && is_array($item['keyTitles']) ? $item['keyTitles'] : array();
                $ignoreScientific = !isset($state['options']['ignoreScientificNoise'])
                    || (bool) $state['options']['ignoreScientificNoise'];
                $expanded = $differ->expand($item, $sheetA, $sheetB, $aligns[$key], $keyTitles, $ignoreScientific);
                foreach (array('added', 'removed', 'changed', 'unchanged', 'conflicts') as $field) {
                    $delta[$field] += $expanded[$field];
                }
                foreach ($expanded['lines'] as $line) {
                    $lines[] = $line;
                }
            }
        }
        $this->appendLines($dir . '/diff.jsonl', $lines);
        $next = $cursor + count($items);
        $finished = $next >= $total;
        $state = $this->jobs->update($jobId, function (array $state) use ($delta, $next, $finished) {
            if ($state['status'] === 'cancelled') {
                return $state;
            }
            foreach ($delta as $field => $value) {
                $state['summary'][$field] += $value;
            }
            $state['cursor'] = $next;
            if ($finished) {
                $state['status'] = 'finalizing';
            }

            return $state;
        });
        if ($state['status'] === 'finalizing') {
            $this->writeReport($state);
            $state = $this->jobs->update($jobId, function (array $state) {
                if ($state['status'] === 'cancelled') {
                    return $state;
                }
                $state['status'] = 'done';
                $state['reportName'] = $this->reportName($state);

                return $state;
            });
        }

        return $this->payload($state, $lines);
    }

    /** @return array<string,mixed> */
    public function cancel(string $jobId): array
    {
        return $this->jobs->update($jobId, function (array $state) {
            if ($state['status'] !== 'done') {
                $state['status'] = 'cancelled';
            }

            return $state;
        });
    }

    /** @return array{path:string,name:string} */
    public function report(string $jobId): array
    {
        $state = $this->jobs->read($jobId);
        if ($state['status'] !== 'done' || empty($state['reportName'])) {
            throw new AppException('Отчёт ещё не готов.');
        }
        $path = $this->jobs->dir($jobId) . '/report.xlsx';
        if (!is_file($path)) {
            throw new AppException('Файл отчёта не найден.', 404);
        }

        return array('path' => $path, 'name' => (string) $state['reportName']);
    }

    public function reportHtml(string $jobId, string $downloadUrl): string
    {
        $state = $this->jobs->read($jobId);
        if ($state['status'] !== 'done' || empty($state['reportName'])) {
            throw new AppException('Отчёт ещё не готов.');
        }
        $diffPath = $this->jobs->dir($jobId) . '/diff.jsonl';
        if (!is_file($diffPath)) {
            throw new AppException('Данные отчёта не найдены.', 404);
        }

        return (new HtmlReportWriter())->render($state, $diffPath, $downloadUrl);
    }

    /** @param array<string,mixed> $file */
    private function assertUpload(array $file, string $which): void
    {
        if (!isset($file['error'], $file['tmp_name'], $file['name'])) {
            throw new AppException('Не передан ' . $which . ' файл.');
        }
        $error = (int) $file['error'];
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new AppException('Файл больше лимита PHP (upload_max_filesize / post_max_size).');
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new AppException('Ошибка загрузки ' . $which . ' файла.');
        }
        $size = isset($file['size']) ? (int) $file['size'] : 0;
        if ($size > (int) $this->config['max_upload_bytes']) {
            throw new AppException('Файл больше ' . (int) ($this->config['max_upload_bytes'] / 1024 / 1024) . ' МБ.');
        }
        if (!is_uploaded_file((string) $file['tmp_name'])) {
            throw new AppException('Загрузка ' . $which . ' файла отклонена.');
        }
    }

    private function extension(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $ext = preg_replace('/[^a-z0-9]/', '', (string) $ext);

        return $ext === '' ? 'bin' : $ext;
    }

    /** @return array<string,mixed> */
    private function parseSide(string $path, string $originalName): array
    {
        $module = $this->modules->resolve(FileProbe::fromPath($path, $originalName));
        $parsed = $module->parse($path);

        return array(
            'kind' => $parsed->kind,
            'moduleId' => $parsed->moduleId,
            'sheets' => $parsed->sheets,
            'blocks' => $parsed->blocks,
            'warnings' => $parsed->warnings,
            'meta' => $parsed->meta,
        );
    }

    /** @param array<string,mixed> $parsed */
    private function storeParsed(string $dir, string $side, array $parsed): void
    {
        $lightSheets = array();
        foreach ($parsed['sheets'] as $index => $sheet) {
            JsonFile::write($dir . '/sheet_' . $side . '_' . $index . '.json', $sheet);
            $lightSheets[] = array(
                'name' => $sheet['name'],
                'headers' => $sheet['headers'],
                'rows' => count($sheet['rows']),
            );
        }
        if (count($parsed['blocks']) > 0 || $parsed['kind'] === 'text') {
            JsonFile::write($dir . '/blocks_' . $side . '.json', $parsed['blocks']);
        }
        JsonFile::write($dir . '/meta_' . $side . '.json', array(
            'kind' => $parsed['kind'],
            'moduleId' => $parsed['moduleId'],
            'warnings' => $parsed['warnings'],
            'meta' => $parsed['meta'],
            'sheets' => $lightSheets,
            'blocks' => $parsed['kind'] === 'text' ? count($parsed['blocks']) : 0,
        ));
    }

    /**
     * @param array<string,mixed> $parsedA
     * @param array<string,mixed> $parsedB
     * @param array<int,string> $warnings
     * @return array<string,mixed>
     */
    private function prepareTable(array $parsedA, array $parsedB, array $warnings): array
    {
        $pairing = $this->pairSheets($parsedA['sheets'], $parsedB['sheets']);
        if (count($pairing['pairs']) === 0) {
            throw new AppException('Не удалось сопоставить листы двух файлов.');
        }
        $first = $pairing['pairs'][0];
        $sheetA = $parsedA['sheets'][$first['a']];
        $sheetB = $parsedB['sheets'][$first['b']];
        $key = (new KeyDetector())->suggest($sheetA['headers'], $sheetA['rows'], $sheetB['rows']);
        $sheetInfo = array();
        foreach ($pairing['pairs'] as $pair) {
            $sheetInfo[] = array(
                'indexA' => $pair['a'],
                'indexB' => $pair['b'],
                'nameA' => $parsedA['sheets'][$pair['a']]['name'],
                'nameB' => $parsedB['sheets'][$pair['b']]['name'],
                'headers' => $parsedA['sheets'][$pair['a']]['headers'],
                'rowsA' => count($parsedA['sheets'][$pair['a']]['rows']),
                'rowsB' => count($parsedB['sheets'][$pair['b']]['rows']),
                'matchedBy' => $pair['matchedBy'],
            );
        }
        foreach ($pairing['unmatchedA'] as $index) {
            $warnings[] = 'Лист только в первом файле: «' . $parsedA['sheets'][$index]['name'] . '».';
        }
        foreach ($pairing['unmatchedB'] as $index) {
            $warnings[] = 'Лист только во втором файле: «' . $parsedB['sheets'][$index]['name'] . '».';
        }
        if ($key['confidence'] === 'low') {
            $warnings[] = $key['reason'];
        }
        $scientific = !empty($parsedA['meta']['scientificNotation']) || !empty($parsedB['meta']['scientificNotation']);

        return array(
            'warnings' => $warnings,
            'sheets' => $sheetInfo,
            'key' => $key,
            'scientificNotation' => $scientific,
        );
    }

    /** @param array<string,mixed> $parsedA @param array<string,mixed> $parsedB
     * @return array<string,mixed> */
    private function prepareText(array $parsedA, array $parsedB): array
    {
        return array(
            'warnings' => array(),
            'blocksA' => count($parsedA['blocks']),
            'blocksB' => count($parsedB['blocks']),
            'previewA' => array_slice($parsedA['blocks'], 0, 3),
            'previewB' => array_slice($parsedB['blocks'], 0, 3),
        );
    }

    /**
     * @param array<int,array<string,mixed>> $sheetsA
     * @param array<int,array<string,mixed>> $sheetsB
     * @return array{pairs:array<int,array<string,mixed>>,unmatchedA:array<int,int>,unmatchedB:array<int,int>}
     */
    private function pairSheets(array $sheetsA, array $sheetsB): array
    {
        if (count($sheetsA) === 1 && count($sheetsB) === 1) {
            return array('pairs' => array(array('a' => 0, 'b' => 0, 'matchedBy' => 'single')), 'unmatchedA' => array(), 'unmatchedB' => array());
        }
        $pairs = array();
        $usedA = array();
        $usedB = array();
        foreach ($sheetsA as $ia => $sheetA) {
            foreach ($sheetsB as $ib => $sheetB) {
                if (isset($usedB[$ib])) {
                    continue;
                }
                if ($this->normName($sheetA['name']) === $this->normName($sheetB['name'])) {
                    $pairs[] = array('a' => $ia, 'b' => $ib, 'matchedBy' => 'name');
                    $usedA[$ia] = true;
                    $usedB[$ib] = true;
                    break;
                }
            }
        }
        $leftA = array();
        foreach ($sheetsA as $ia => $_) {
            if (!isset($usedA[$ia])) {
                $leftA[] = $ia;
            }
        }
        $leftB = array();
        foreach ($sheetsB as $ib => $_) {
            if (!isset($usedB[$ib])) {
                $leftB[] = $ib;
            }
        }
        $extra = min(count($leftA), count($leftB));
        for ($i = 0; $i < $extra; $i++) {
            $pairs[] = array('a' => $leftA[$i], 'b' => $leftB[$i], 'matchedBy' => 'order');
            $usedA[$leftA[$i]] = true;
            $usedB[$leftB[$i]] = true;
        }
        $unmatchedA = array();
        foreach ($leftA as $index) {
            if (!isset($usedA[$index])) {
                $unmatchedA[] = $index;
            }
        }
        $unmatchedB = array();
        foreach ($leftB as $index) {
            if (!isset($usedB[$index])) {
                $unmatchedB[] = $index;
            }
        }

        return array('pairs' => $pairs, 'unmatchedA' => $unmatchedA, 'unmatchedB' => $unmatchedB);
    }

    /** @param array<string,mixed> $state @param array<string,mixed> $options
     * @return array{total:int,warnings:array<int,string>,summary:array<string,int>} */
    private function buildTableWork(string $dir, array $state, array $options): array
    {
        $prepare = $state['prepare'];
        $selected = $this->selectedColumns($prepare, $options);
        $differ = new TableDiffer();
        $handle = fopen($dir . '/work.jsonl', 'wb');
        if ($handle === false) {
            throw new AppException('Не удалось подготовить сравнение.');
        }
        $aligns = array();
        $warnings = array();
        $total = 0;
        foreach ($prepare['sheets'] as $pair) {
            $sheetA = JsonFile::read($dir . '/sheet_a_' . $pair['indexA'] . '.json');
            $sheetB = JsonFile::read($dir . '/sheet_b_' . $pair['indexB'] . '.json');
            $columns = $this->columnsForSheet($selected, $sheetA['headers']);
            $label = $sheetA['name'] === $sheetB['name'] ? $sheetA['name'] : ($sheetA['name'] . ' → ' . $sheetB['name']);
            if ($columns === null) {
                $warnings[] = 'Лист «' . $label . '»: ключевые колонки не найдены, сравнение по порядку строк.';
                $columns = array();
            }
            $plan = $differ->buildPlan($sheetA, $sheetB, $columns, $label);
            $warnings = array_merge($warnings, $plan['warnings']);
            $sheetKey = $pair['indexA'] . ':' . $pair['indexB'];
            $aligns[$sheetKey] = $plan['align'];
            $keyTitles = array();
            foreach ($columns as $columnIndex) {
                if (isset($sheetA['headers'][$columnIndex])) {
                    $keyTitles[] = (string) $sheetA['headers'][$columnIndex];
                }
            }
            foreach ($plan['items'] as $item) {
                $item['aSheet'] = $pair['indexA'];
                $item['bSheet'] = $pair['indexB'];
                $item['sheetKey'] = $sheetKey;
                $item['keyTitles'] = $keyTitles;
                fwrite($handle, json_encode($item, JSON_UNESCAPED_UNICODE) . "\n");
                $total++;
            }
        }
        fclose($handle);
        JsonFile::write($dir . '/align.json', $aligns);

        return array('total' => $total, 'warnings' => $warnings, 'summary' => $this->jobs->emptySummary());
    }

    /** @param array<string,mixed> $options
     * @return array{total:int,warnings:array<int,string>,summary:array<string,int>} */
    private function buildTextWork(string $dir, array $options): array
    {
        $blocksA = $this->normalizeBlocks(JsonFile::read($dir . '/blocks_a.json'), $options);
        $blocksB = $this->normalizeBlocks(JsonFile::read($dir . '/blocks_b.json'), $options);
        $result = (new TextDiffer())->diff($blocksA, $blocksB);
        $handle = fopen($dir . '/work.jsonl', 'wb');
        if ($handle === false) {
            throw new AppException('Не удалось подготовить сравнение текста.');
        }
        foreach ($result['ops'] as $op) {
            fwrite($handle, json_encode($op, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
        }
        fclose($handle);
        $warnings = array();
        if ($result['lowConfidence']) {
            $warnings[] = 'Тексты сильно различаются или слишком большие для точного выравнивания. Часть сравнения идёт по порядку абзацев.';
        }

        return array('total' => count($result['ops']), 'warnings' => $warnings, 'summary' => $this->jobs->emptySummary());
    }

    /**
     * @param array<string,mixed> $prepare
     * @param array<string,mixed> $options
     * @return array<int,string>
     */
    private function selectedColumns(array $prepare, array $options): array
    {
        $headers = $prepare['sheets'][0]['headers'];
        if (array_key_exists('keyColumns', $options) && is_array($options['keyColumns']) && count($options['keyColumns']) === 0) {
            return array();
        }
        $indexes = array();
        if (isset($options['keyColumns']) && is_array($options['keyColumns'])) {
            foreach ($options['keyColumns'] as $index) {
                $indexes[] = (int) $index;
            }
        } else {
            $indexes = $prepare['key']['columns'];
        }
        $names = array();
        foreach ($indexes as $index) {
            if (!isset($headers[$index])) {
                throw new AppException('Выбрана несуществующая колонка ключа.');
            }
            $names[] = (string) $headers[$index];
        }

        return $names;
    }

    /** @param array<int,string> $names @param array<int,string> $headers @return array<int,int>|null */
    private function columnsForSheet(array $names, array $headers): ?array
    {
        if (count($names) === 0) {
            return array();
        }
        $indexes = array();
        foreach ($names as $name) {
            $found = array_search($name, $headers, true);
            if ($found === false) {
                return null;
            }
            $indexes[] = (int) $found;
        }

        return $indexes;
    }

    /** @param array<int,mixed> $blocks @param array<string,mixed> $options @return array<int,string> */
    private function normalizeBlocks(array $blocks, array $options): array
    {
        $ignoreEmpty = !isset($options['ignoreEmpty']) || (bool) $options['ignoreEmpty'];
        $collapse = !empty($options['collapseWhitespace']);
        $out = array();
        foreach ($blocks as $block) {
            $text = str_replace(array("\r\n", "\r"), "\n", (string) $block);
            if ($collapse) {
                $text = (string) preg_replace('/[ \t]+/u', ' ', $text);
            }
            $text = trim($text);
            if ($text === '' && $ignoreEmpty) {
                continue;
            }
            $out[] = $text;
        }

        return $out;
    }

    /** @param array<string,int> $delta */
    private function countLine(array &$delta, string $status): void
    {
        if ($status === 'added') {
            $delta['added']++;
        } elseif ($status === 'removed') {
            $delta['removed']++;
        } elseif ($status === 'changed') {
            $delta['changed']++;
        } elseif ($status === 'conflict') {
            $delta['conflicts']++;
        } else {
            $delta['unchanged']++;
        }
    }

    /** @param array<string,array<string,mixed>> $cache
     * @return array<string,mixed> */
    private function cachedSheet(string $dir, string $side, int $index, array &$cache): array
    {
        $key = $side . $index;
        if (!isset($cache[$key])) {
            $cache[$key] = JsonFile::read($dir . '/sheet_' . $side . '_' . $index . '.json');
        }

        return $cache[$key];
    }

    /** @return array<int,array<string,mixed>> */
    private function readWork(string $path, int $cursor, int $limit): array
    {
        if (!is_file($path)) {
            return array();
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return array();
        }
        $items = array();
        $index = 0;
        while (($line = fgets($handle)) !== false) {
            if ($index >= $cursor && count($items) < $limit) {
                $decoded = json_decode($line, true);
                if (is_array($decoded)) {
                    $items[] = $decoded;
                }
            }
            $index++;
            if ($index >= $cursor + $limit) {
                break;
            }
        }
        fclose($handle);

        return $items;
    }

    /** @param array<int,array<string,mixed>> $lines */
    private function appendLines(string $path, array $lines): void
    {
        if (count($lines) === 0) {
            return;
        }
        $chunks = array();
        foreach ($lines as $line) {
            $encoded = json_encode($line, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($encoded !== false) {
                $chunks[] = $encoded;
            }
        }
        file_put_contents($path, implode("\n", $chunks) . "\n", FILE_APPEND | LOCK_EX);
    }

    private function resetOutputs(string $dir): void
    {
        foreach (array('work.jsonl', 'diff.jsonl', 'align.json', 'report.xlsx') as $name) {
            $path = $dir . '/' . $name;
            if (is_file($path)) {
                unlink($path);
            }
        }
        file_put_contents($dir . '/diff.jsonl', '');
    }

    /** @param array<string,mixed> $state */
    private function writeReport(array $state): void
    {
        $dir = $this->jobs->dir((string) $state['id']);
        $path = $dir . '/report.xlsx';
        if ($state['kind'] === 'text') {
            (new TextReportWriter())->write($path, $state, $dir . '/diff.jsonl');

            return;
        }
        (new ExcelReportWriter())->write($path, $state, $dir . '/diff.jsonl');
    }

    /** @param array<string,mixed> $state */
    private function reportName(array $state): string
    {
        $a = $this->safePart((string) $state['fileA']['name']);
        $b = $this->safePart((string) $state['fileB']['name']);

        return 'diff_' . $a . '_vs_' . $b . '_' . date('Ymd_His') . '.xlsx';
    }

    private function safePart(string $name): string
    {
        $base = pathinfo($name, PATHINFO_FILENAME);
        $base = (string) preg_replace('/[^\p{L}\p{N}_-]+/u', '_', $base);
        $base = trim($base, '_');
        if ($base === '') {
            $base = 'file';
        }

        if (mb_strlen($base, 'UTF-8') > 30) {
            $base = mb_substr($base, 0, 30, 'UTF-8');
        }

        return $base;
    }

    private function baseName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));

        return $name === '' ? 'file' : $name;
    }

    /** @param array<string,mixed> $state @param array<int,array<string,mixed>> $rows
     * @return array<string,mixed> */
    private function payload(array $state, array $rows): array
    {
        $total = (int) $state['total'];
        $cursor = (int) $state['cursor'];
        $progress = $total === 0 ? 100 : (int) floor(min(100, ($cursor / $total) * 100));
        if ($state['status'] === 'done') {
            $progress = 100;
        }

        return array(
            'ok' => true,
            'jobId' => $state['id'],
            'status' => $state['status'],
            'kind' => $state['kind'],
            'progress' => $progress,
            'summary' => $state['summary'],
            'warnings' => $state['warnings'],
            'rows' => $rows,
            'reportReady' => $state['status'] === 'done',
            'reportName' => $state['reportName'],
            'prepare' => $state['prepare'],
            'modules' => $state['modules'],
            'fileA' => $state['fileA']['name'],
            'fileB' => $state['fileB']['name'],
        );
    }

    private function normName(string $name): string
    {
        return trim(Strings::lower($name));
    }
}
