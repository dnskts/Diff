<?php

declare(strict_types=1);

use Diff\App;
use Diff\AppException;
use Diff\Http\ApiKernel;

require dirname(__DIR__) . '/vendor/autoload.php';

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', dirname(__DIR__) . '/storage/error.log');

try {
    (new ApiKernel(App::compare()))->handle();
} catch (AppException $e) {
    http_response_code($e->http);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('ok' => false, 'error' => $e->getMessage()), JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log($e->__toString());
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('ok' => false, 'error' => 'Внутренняя ошибка сервера.'), JSON_UNESCAPED_UNICODE);
}
