<?php

declare(strict_types=1);

namespace Diff\Modules\Contract;

class ParsedContent
{
    /** @var string */
    public $kind = 'table';

    /** @var string */
    public $moduleId = '';

    /** @var array<int,array<string,mixed>> */
    public $sheets = array();

    /** @var array<int,string> */
    public $blocks = array();

    /** @var array<int,string> */
    public $warnings = array();

    /** @var array<string,mixed> */
    public $meta = array();
}
