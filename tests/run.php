<?php

declare(strict_types=1);

/**
 * Лёгкие проверки ядра.
 * Не подключаем PhpWord: его OLERead на Windows зависает на битых DOC.
 *
 * Запуск: php tests/run.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Diff\App;
use Diff\Diff\Table\KeyDetector;
use Diff\Diff\Table\TableDiffer;
use Diff\Diff\Text\TextDiffer;
use Diff\Modules\Table\CsvModule;
use Diff\Modules\Table\HtmlExcelModule;
use Diff\Modules\Text\DocModule;
use Diff\Modules\Text\DocxModule;
use Diff\Normalize\ExcelValueNormalizer;
use PhpOffice\PhpSpreadsheet\Shared\Date;

set_time_limit(30);
error_reporting(E_ALL);
ini_set('display_errors', '1');

$failed = 0;

function out(string $line): void
{
    echo $line . "\n";
    if (defined('STDERR')) {
        fwrite(STDERR, $line . "\n");
    }
}

function check(bool $cond, string $message): void
{
    global $failed;
    if (!$cond) {
        $failed++;
    }
    out(($cond ? 'OK  ' : 'FAIL ') . $message);
}

out('start');

// Защита: если где-то снова потянут PhpWord — сразу видно.
if (class_exists('PhpOffice\\PhpWord\\IOFactory', false)
    || class_exists('PhpOffice\\PhpWord\\Shared\\OLERead', false)
) {
    out('FAIL PhpWord already loaded — tests must not use it');
    exit(1);
}

$config = App::config();
$norm = new ExcelValueNormalizer(10);

check($norm->fromString('1,0') === '1', 'comma decimal 1,0 -> 1');
check($norm->fromString('1.0') === '1', '1.0 -> 1');
check($norm->fromString('1 234,50') === '1234.5', 'grouped ru number');
check($norm->fromString('15%') === '0.15', 'percent string');
check($norm->fromString('00123') === '00123', 'leading zeros stay text');
check($norm->fromString('31.12.2024') === '2024-12-31', 'ru date');
check($norm->fromString('1.0000000002') === '1', 'float noise string');
check($norm->fromExcel(1.0000000002, 'n', 'General') === '1', 'float noise excel');
check($norm->fromExcel(0.15, 'n', '0%') === '0.15', 'excel percent fraction');
check($norm->fromExcel(0.15, 'n', '0%') === $norm->fromString('15%'), '0.15 percent equals 15%');

$serial = Date::PHPToExcel(new DateTime('2024-02-01'));
check($norm->fromExcel($serial, 'n', 'dd.mm.yyyy') === '2024-02-01', 'excel serial date');
check($norm->fromExcel('00123', 's', '@') === '00123', 'excel text code');
check(ExcelValueNormalizer::isLikelyExcelScientificNoise('123123000000', '123000000000'), 'excel scientific noise ignored');
check(!ExcelValueNormalizer::isLikelyExcelScientificNoise('123000000000', '124000000000'), 'real long id change kept');

$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'diff-tests-' . uniqid('', true);
if (!@mkdir($dir) && !is_dir($dir)) {
    out('FAIL cannot create temp dir');
    exit(1);
}

out('csv');
$csv = new CsvModule($norm, $config);
file_put_contents($dir . '/a.csv', "id;name;qty\n1;Яблоко;1,0\n2;Груша;5\n");
file_put_contents($dir . '/b.csv', "id;name;qty\n1;Яблоко;1\n2;Груша;6\n3;Слива;2\n");
$a = $csv->parse($dir . '/a.csv');
$b = $csv->parse($dir . '/b.csv');
check($a->sheets[0]['rows'][0][2] === '1', 'csv qty normalized');
$key = (new KeyDetector())->suggest($a->sheets[0]['headers'], $a->sheets[0]['rows'], $b->sheets[0]['rows']);
check($key['mode'] === 'key' && $key['columns'] === array(0), 'id column detected');
$differ = new TableDiffer();
$plan = $differ->buildPlan($a->sheets[0], $b->sheets[0], $key['columns'], 'CSV');
$added = 0;
$changed = 0;
$unchanged = 0;
foreach ($plan['items'] as $item) {
    $expanded = $differ->expand($item, $a->sheets[0], $b->sheets[0], $plan['align']);
    $added += $expanded['added'];
    $changed += $expanded['changed'];
    $unchanged += $expanded['unchanged'];
}
check($unchanged === 1 && $changed === 1 && $added === 1, 'csv diff counts');

out('html');
$htmlMod = new HtmlExcelModule($norm, $config);
file_put_contents($dir . '/t.html', '<html><body><table><tr><th>id</th><th>qty</th></tr><tr><td>7</td><td>1,5</td></tr></table></body></html>');
$html = $htmlMod->parse($dir . '/t.html');
check($html->sheets[0]['rows'][0] === array('7', '1.5'), 'html excel table');

out('text');
$text = (new TextDiffer())->diff(array('один', 'два', 'три'), array('один', '2', 'три'));
$changedText = 0;
foreach ($text['ops'] as $op) {
    if ($op['status'] === 'changed') {
        $changedText++;
    }
}
check($changedText === 1 && $text['lowConfidence'] === false, 'text replacement');

out('docx');
$docx = $dir . '/a.docx';
$docxXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
    . '<w:body>'
    . '<w:p><w:r><w:t>Абзац один</w:t></w:r></w:p>'
    . '<w:p><w:r><w:t>Абзац два</w:t></w:r></w:p>'
    . '</w:body></w:document>';
$zip = new ZipArchive();
if ($zip->open($docx, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    check(false, 'docx zip create');
} else {
    $zip->addFromString(
        '[Content_Types].xml',
        '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
        . '</Types>'
    );
    $zip->addFromString(
        '_rels/.rels',
        '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
        . '</Relationships>'
    );
    $zip->addFromString('word/document.xml', $docxXml);
    $zip->close();
    $docxParsed = (new DocxModule())->parse($docx);
    check(count($docxParsed->blocks) === 2 && $docxParsed->blocks[0] === 'Абзац один', 'docx paragraphs');
}

out('doc');
// Только мусор без OLE-сигнатуры — иначе старые пути через PhpWord зависали.
file_put_contents($dir . '/bad.doc', str_repeat("\x00\x01\x02\x03\x04\x05\x06\x07", 16));
$docFailed = false;
try {
    (new DocModule())->parse($dir . '/bad.doc');
} catch (Throwable $e) {
    $docFailed = (strpos($e->getMessage(), 'DOCX') !== false) || (strpos($e->getMessage(), 'TXT') !== false);
}
check($docFailed, 'bad doc asks to resave');

check(
    !class_exists('PhpOffice\\PhpWord\\Shared\\OLERead', false)
    && !class_exists('PhpOffice\\PhpWord\\IOFactory', false),
    'PhpWord was not loaded'
);

foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: array() as $file) {
    @unlink($file);
}
@rmdir($dir);

out($failed === 0 ? 'ALL PASSED' : 'FAILED ' . $failed);
exit($failed === 0 ? 0 : 1);
