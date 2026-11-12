<?php

declare(strict_types=1);

namespace Diff;

class AppException extends \RuntimeException
{
    /** @var int */
    public $http;

    public function __construct(string $message, int $http = 400)
    {
        parent::__construct($message);
        $this->http = $http;
    }
}
