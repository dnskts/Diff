<?php

declare(strict_types=1);

return array(
    'timezone' => 'Europe/Moscow',
    'storage' => dirname(__DIR__) . '/storage/jobs',
    'max_upload_bytes' => 50 * 1024 * 1024,
    'max_rows' => 100000,
    'max_blocks' => 50000,
    'chunk_size' => 250,
    'ui_row_cap' => 500,
    'job_ttl_hours' => 48,
    'number_decimals' => 10,
);
