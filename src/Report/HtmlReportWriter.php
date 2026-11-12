<?php

declare(strict_types=1);

namespace Diff\Report;

class HtmlReportWriter
{
    /** @param array<string,mixed> $state */
    public function render(array $state, string $diffPath, string $downloadUrl): string
    {
        $summary = isset($state['summary']) && is_array($state['summary']) ? $state['summary'] : array();
        $nameA = isset($state['fileA']['name']) ? (string) $state['fileA']['name'] : 'A';
        $nameB = isset($state['fileB']['name']) ? (string) $state['fileB']['name'] : 'B';

        $isText = isset($state['kind']) && $state['kind'] === 'text';
        $rowsHtml = '';
        $written = 0;
        $handle = fopen($diffPath, 'rb');
        if ($handle !== false) {
            while (($line = fgets($handle)) !== false) {
                $item = json_decode($line, true);
                if (!is_array($item)) {
                    continue;
                }
                $written++;
                if ($written > 50000) {
                    break;
                }
                $status = (string) ($item['status'] ?? '');
                if ($isText) {
                    $rowsHtml .= '<tr class="row-' . $this->esc($status) . '">'
                        . '<td>' . $this->esc($this->label($status)) . '</td>'
                        . '<td class="was">' . $this->esc((string) ($item['before'] ?? '')) . '</td>'
                        . '<td class="became">' . $this->esc((string) ($item['after'] ?? '')) . '</td>'
                        . '</tr>';
                } else {
                    $rowsHtml .= '<tr class="row-' . $this->esc($status) . '">'
                        . '<td>' . $this->esc($this->label($status)) . '</td>'
                        . '<td>' . $this->esc((string) ($item['sheet'] ?? '')) . '</td>'
                        . '<td>' . $this->esc((string) ($item['key'] ?? '')) . '</td>'
                        . '<td>' . $this->esc((string) ($item['column'] ?? '')) . '</td>'
                        . '<td class="was">' . $this->esc((string) ($item['before'] ?? '')) . '</td>'
                        . '<td class="became">' . $this->esc((string) ($item['after'] ?? '')) . '</td>'
                        . '</tr>';
                }
            }
            fclose($handle);
        }
        $colspan = $isText ? 3 : 6;
        if ($rowsHtml === '') {
            $rowsHtml = '<tr><td colspan="' . $colspan . '">Отличий нет</td></tr>';
        }

        $added = (int) ($summary['added'] ?? 0);
        $removed = (int) ($summary['removed'] ?? 0);
        $changed = (int) ($summary['changed'] ?? 0);
        $thead = $isText
            ? '<th>Статус</th><th>Было</th><th>Стало</th>'
            : '<th>Статус</th><th>Лист</th><th>Ключ</th><th>Поле</th><th>Было</th><th>Стало</th>';

        return '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Отчёт сравнения</title><style>'
            . 'body{margin:0;font:14px/1.4 "Segoe UI",Arial,sans-serif;color:#333;background:#eef2f4}'
            . '.bar{display:flex;gap:10px;align-items:center;justify-content:space-between;padding:12px 16px;background:#fff;border-bottom:1px solid #dfe3e6;position:sticky;top:0;z-index:2}'
            . '.meta{color:#525c69;font-size:13px}'
            . '.btn{display:inline-block;padding:8px 14px;border-radius:6px;background:#2fc6f6;color:#fff;text-decoration:none;border:0;font:inherit;cursor:pointer}'
            . '.btn:hover{background:#11b6ec}'
            . '.wrap{padding:16px}'
            . '.panel{background:#fff;border:1px solid #dfe3e6;border-radius:10px;overflow:auto}'
            . 'table{width:100%;border-collapse:collapse}'
            . 'th,td{text-align:left;padding:6px 10px;border-bottom:1px solid #e6eaed;vertical-align:top;white-space:pre-wrap;word-break:break-word}'
            . 'th{background:#edf1f3;position:sticky;top:0}'
            . '.was{color:#c83b36}.became{color:#3f7a00}'
            . '.chips span{display:inline-block;margin-right:6px;padding:2px 8px;border-radius:10px;font-weight:600;font-size:12px}'
            . '.c-add{background:#e5f6c8;color:#3f7a00}.c-del{background:#ffe8e6;color:#c83b36}.c-chg{background:#fff4d6;color:#9a6400}'
            . '</style></head><body>'
            . '<div class="bar"><div><div class="chips">'
            . '<span class="c-add">+' . $added . '</span>'
            . '<span class="c-del">−' . $removed . '</span>'
            . '<span class="c-chg">~' . $changed . '</span>'
            . '</div><div class="meta">' . $this->esc($nameA) . ' → ' . $this->esc($nameB) . '</div></div>'
            . '<a class="btn" href="' . $this->esc($downloadUrl) . '">Скачать</a></div>'
            . '<div class="wrap"><div class="panel"><table><thead><tr>'
            . $thead
            . '</tr></thead><tbody>' . $rowsHtml . '</tbody></table></div></div>'
            . '</body></html>';
    }

    private function label(string $status): string
    {
        $map = array(
            'added' => 'Добавлено',
            'removed' => 'Удалено',
            'changed' => 'Изменено',
            'conflict' => 'Конфликт',
        );

        return isset($map[$status]) ? $map[$status] : $status;
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
