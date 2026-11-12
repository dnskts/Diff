<?php

declare(strict_types=1);

namespace Diff\Modules;

use Diff\AppException;
use Diff\Detect\FileProbe;
use Diff\Modules\Contract\FormatModule;

class ModuleRegistry
{
    /** @var array<int,FormatModule> */
    private $modules;

    /** @param array<int,FormatModule> $modules */
    public function __construct(array $modules)
    {
        $this->modules = $modules;
    }

    public function resolve(FileProbe $probe): FormatModule
    {
        foreach ($this->modules as $module) {
            if ($module->supports($probe)) {
                return $module;
            }
        }

        throw new AppException('Формат не поддерживается: ' . $probe->originalName);
    }
}
