<?php

declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Сравнение файлов</title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
    <div id="progress" class="progress" aria-hidden="true">
        <div class="progress__track">
            <div id="progress-bar" class="progress__bar"></div>
        </div>
        <span id="progress-pct" class="progress__pct">0%</span>
    </div>
    <main class="page">
        <div class="toolbar">
            <section class="panel panel--upload" id="upload-panel">
                <div class="upload-row">
                    <div class="drops">
                        <label class="drop" id="drop-a">
                            <span class="drop__title" id="title-a">Было</span>
                            <span class="drop__file" id="name-a">Файл не выбран</span>
                            <span class="btn btn-secondary btn-sm">Выбрать</span>
                            <input type="file" id="file-a" accept=".csv,.tsv,.xlsx,.xlsm,.xls,.html,.htm,.txt,.docx,.doc">
                        </label>
                        <label class="drop" id="drop-b">
                            <span class="drop__title" id="title-b">Стало</span>
                            <span class="drop__file" id="name-b">Файл не выбран</span>
                            <span class="btn btn-secondary btn-sm">Выбрать</span>
                            <input type="file" id="file-b" accept=".csv,.tsv,.xlsx,.xlsm,.xls,.html,.htm,.txt,.docx,.doc">
                        </label>
                    </div>
                    <div class="upload-actions">
                        <label class="btn btn-secondary" id="btn-both">
                            Два файла
                            <input type="file" id="file-both" multiple accept=".csv,.tsv,.xlsx,.xlsm,.xls,.html,.htm,.txt,.docx,.doc" hidden>
                        </label>
                        <button type="button" class="btn btn-primary" id="btn-prepare">Сравнить</button>
                    </div>
                </div>
            </section>
            <div class="toolbar__actions" id="toolbar-actions">
                <button type="button" class="btn btn-secondary hidden" id="btn-cancel">Отменить</button>
                <button type="button" class="btn btn-primary hidden" id="btn-download">Отчёт</button>
                <button type="button" class="btn btn-secondary" id="btn-reset">Новое сравнение</button>
            </div>
        </div>

        <div class="modal hidden" id="report-modal" aria-hidden="true">
            <div class="modal__dialog">
                <div class="modal__bar">
                    <strong>Предпросмотр отчёта</strong>
                    <button type="button" class="btn btn-secondary btn-sm" id="btn-report-close">Закрыть</button>
                </div>
                <iframe class="modal__frame" id="report-frame" title="Предпросмотр отчёта"></iframe>
            </div>
        </div>

        <div class="workspace hidden" id="workspace">
            <section class="panel workspace__main" id="result-panel">
                <div class="result-head">
                    <h2>Отличия</h2>
                    <p class="progress-label" id="progress-label">Выберите параметры справа и нажмите «Найти отличия».</p>
                    <div class="chips" id="chips"></div>
                </div>
                <div class="result-wrap">
                    <table class="grid" id="grid">
                        <thead id="grid-head"></thead>
                        <tbody id="grid-body"></tbody>
                    </table>
                </div>
                <p class="muted hidden" id="cap-note"></p>
            </section>

            <aside class="workspace__side" id="side-panel">
                <button type="button" class="side-toggle" id="btn-side-toggle" title="Свернуть параметры" aria-expanded="true">
                    <span class="side-toggle__icon" aria-hidden="true">›</span>
                    <span class="side-toggle__label">Параметры</span>
                </button>
                <section class="panel panel--side" id="settings-panel">
                    <h2>Параметры сравнения</h2>
                    <div class="actions actions--stack actions--side-top">
                        <button type="button" class="btn btn-primary" id="btn-start">Найти отличия</button>
                        <button type="button" class="btn btn-secondary" id="btn-positional" title="Сравнивает строку N первого файла со строкой N второго, без ключа">По порядку строк</button>
                    </div>
                    <div id="warnings" class="notice hidden"></div>
                    <div id="scientific-box" class="notice hidden">
                        <div>В файлах есть числа в научной записи Excel (1,23E+11).</div>
                        <label class="check"><input type="checkbox" id="ignore-scientific" checked><span>Игнорировать</span></label>
                    </div>
                    <div id="sheet-info" class="meta"></div>
                    <div id="key-box"></div>
                    <div id="text-box" class="hidden">
                        <label class="check"><input type="checkbox" id="ignore-empty" checked><span>Не учитывать пустые строки</span></label>
                        <label class="check"><input type="checkbox" id="collapse-space"><span>Схлопывать повторные пробелы</span></label>
                    </div>
                </section>
            </aside>
        </div>

        <div id="error" class="error hidden"></div>
    </main>
    <script src="assets/app.js"></script>
</body>
</html>
