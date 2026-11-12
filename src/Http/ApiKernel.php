<?php

declare(strict_types=1);

namespace Diff\Http;

use Diff\AppException;
use Diff\Compare\CompareService;

class ApiKernel
{
    /** @var CompareService */
    private $compare;

    public function __construct(CompareService $compare)
    {
        $this->compare = $compare;
    }

    public function handle(): void
    {
        $action = isset($_GET['action']) ? (string) $_GET['action'] : '';
        if ($action === 'meta') {
            $this->json(array(
                'ok' => true,
                'maxUploadMb' => 50,
                'formats' => array('csv', 'tsv', 'xlsx', 'xlsm', 'xls', 'html', 'txt', 'docx', 'doc'),
            ));
        }
        if ($action === 'upload') {
            $this->guardPostSize();
            $state = $this->compare->upload(
                isset($_FILES['fileA']) ? $_FILES['fileA'] : array(),
                isset($_FILES['fileB']) ? $_FILES['fileB'] : array()
            );
            $this->json(array('ok' => true, 'jobId' => $state['id']));
        }
        $body = $this->body();
        $jobId = '';
        if (isset($body['jobId'])) {
            $jobId = (string) $body['jobId'];
        } elseif (isset($_GET['jobId'])) {
            $jobId = (string) $_GET['jobId'];
        }
        if ($action === 'prepare') {
            $this->jsonState($this->compare->prepare($jobId));
        }
        if ($action === 'start') {
            $this->jsonState($this->compare->start($jobId, $body));
        }
        if ($action === 'process') {
            $this->json($this->compare->process($jobId));
        }
        if ($action === 'cancel') {
            $this->jsonState($this->compare->cancel($jobId));
        }
        if ($action === 'report') {
            $report = $this->compare->report($jobId);
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="diff.xlsx"; filename*=UTF-8\'\'' . rawurlencode($report['name']));
            header('Content-Length: ' . (string) filesize($report['path']));
            readfile($report['path']);

            return;
        }

        throw new AppException('Неизвестное действие.', 404);
    }

    /** @param array<string,mixed> $state */
    private function jsonState(array $state): void
    {
        $state['ok'] = true;
        $this->json($state);
    }

    /** @param array<string,mixed> $data */
    private function json(array $data, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    /** @return array<string,mixed> */
    private function body(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return array();
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new AppException('Некорректный JSON.');
        }

        return $data;
    }

    private function guardPostSize(): void
    {
        $length = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
        if ($length > 0 && count($_POST) === 0 && count($_FILES) === 0) {
            throw new AppException('Запрос больше post_max_size. Увеличьте post_max_size и upload_max_filesize в PHP.');
        }
    }
}
