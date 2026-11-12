<?php

declare(strict_types=1);

namespace Diff;

use Diff\Compare\CompareService;
use Diff\Job\JobRepository;
use Diff\Modules\ModuleRegistry;
use Diff\Modules\Table\CsvModule;
use Diff\Modules\Table\HtmlExcelModule;
use Diff\Modules\Table\XlsxModule;
use Diff\Modules\Text\DocModule;
use Diff\Modules\Text\DocxModule;
use Diff\Modules\Text\TxtModule;
use Diff\Normalize\ExcelValueNormalizer;

class App
{
    /** @var array<string,mixed>|null */
    private static $config;

    /** @return array<string,mixed> */
    public static function config(): array
    {
        if (self::$config === null) {
            self::$config = require dirname(__DIR__) . '/config/config.php';
            date_default_timezone_set((string) self::$config['timezone']);
            ini_set('memory_limit', '512M');
        }

        return self::$config;
    }

    public static function jobs(): JobRepository
    {
        return new JobRepository((string) self::config()['storage'], (int) self::config()['job_ttl_hours']);
    }

    public static function modules(): ModuleRegistry
    {
        $config = self::config();
        $normalizer = new ExcelValueNormalizer((int) $config['number_decimals']);

        return new ModuleRegistry(array(
            new DocxModule(),
            new XlsxModule($normalizer, $config),
            new DocModule(),
            new HtmlExcelModule($normalizer, $config),
            new CsvModule($normalizer, $config),
            new TxtModule($config),
        ));
    }

    public static function compare(): CompareService
    {
        return new CompareService(self::jobs(), self::modules(), self::config());
    }
}
