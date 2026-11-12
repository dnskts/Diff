(function () {
    var jobId = null;
    var kind = null;
    var shown = 0;
    var running = false;
    var uiCap = 500;
    var fileA = null;
    var fileB = null;
    var colWidths = [];
    var manualCols = {};
    var measureCanvas = null;
    var groups = {};
    var groupSeq = 0;

    var el = {
        fileA: document.getElementById('file-a'),
        fileB: document.getElementById('file-b'),
        nameA: document.getElementById('name-a'),
        nameB: document.getElementById('name-b'),
        dropA: document.getElementById('drop-a'),
        dropB: document.getElementById('drop-b'),
        prepare: document.getElementById('btn-prepare'),
        start: document.getElementById('btn-start'),
        positional: document.getElementById('btn-positional'),
        cancel: document.getElementById('btn-cancel'),
        download: document.getElementById('btn-download'),
        reset: document.getElementById('btn-reset'),
        sideToggle: document.getElementById('btn-side-toggle'),
        workspace: document.getElementById('workspace'),
        side: document.getElementById('side-panel'),
        settings: document.getElementById('settings-panel'),
        results: document.getElementById('result-panel'),
        warnings: document.getElementById('warnings'),
        scientificBox: document.getElementById('scientific-box'),
        ignoreScientific: document.getElementById('ignore-scientific'),
        sheets: document.getElementById('sheet-info'),
        keys: document.getElementById('key-box'),
        textBox: document.getElementById('text-box'),
        error: document.getElementById('error'),
        progress: document.getElementById('progress'),
        bar: document.getElementById('progress-bar'),
        pct: document.getElementById('progress-pct'),
        label: document.getElementById('progress-label'),
        chips: document.getElementById('chips'),
        grid: document.getElementById('grid'),
        head: document.getElementById('grid-head'),
        body: document.getElementById('grid-body'),
        cap: document.getElementById('cap-note')
    };

    bindFile(el.fileA, el.nameA, el.dropA, function (file) { fileA = file; });
    bindFile(el.fileB, el.nameB, el.dropB, function (file) { fileB = file; });
    el.prepare.addEventListener('click', onPrepare);
    el.start.addEventListener('click', function () { onStart(false); });
    el.positional.addEventListener('click', function () { onStart(true); });
    el.cancel.addEventListener('click', onCancel);
    el.download.addEventListener('click', downloadReport);
    el.reset.addEventListener('click', resetAll);
    el.sideToggle.addEventListener('click', toggleSide);

    function bindFile(input, label, zone, save) {
        input.addEventListener('change', function () {
            var file = input.files && input.files[0] ? input.files[0] : null;
            save(file);
            label.textContent = file ? file.name : 'Файл не выбран';
            label.title = file ? file.name : '';
        });
        zone.addEventListener('dragover', function (event) {
            event.preventDefault();
            zone.classList.add('is-over');
        });
        zone.addEventListener('dragleave', function () { zone.classList.remove('is-over'); });
        zone.addEventListener('drop', function (event) {
            event.preventDefault();
            zone.classList.remove('is-over');
            var file = event.dataTransfer.files && event.dataTransfer.files[0] ? event.dataTransfer.files[0] : null;
            if (!file) return;
            save(file);
            label.textContent = file.name;
            label.title = file.name;
        });
    }

    function toggleSide() {
        var collapsed = el.workspace.classList.toggle('is-side-collapsed');
        el.sideToggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        el.sideToggle.title = collapsed ? 'Показать параметры' : 'Свернуть параметры';
        el.sideToggle.querySelector('.side-toggle__icon').textContent = collapsed ? '‹' : '›';
    }

    function onPrepare() {
        hideError();
        if (!fileA || !fileB) {
            showError('Выберите оба файла с диска.');
            return;
        }
        setBusy(true, 'Загрузка…');
        setProgress(true, 12);
        var form = new FormData();
        form.append('fileA', fileA, fileA.name);
        form.append('fileB', fileB, fileB.name);
        fetch('api.php?action=upload', { method: 'POST', body: form })
            .then(readJson)
            .then(function (data) {
                jobId = data.jobId;
                setBusy(true, 'Подготовка…');
                setProgress(true, 45);
                return fetch('api.php?action=prepare', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ jobId: jobId })
                }).then(readJson);
            })
            .then(function (data) {
                setProgress(true, 100);
                kind = data.kind;
                renderSettings(data);
                el.workspace.classList.remove('hidden');
                el.workspace.classList.remove('is-side-collapsed');
                el.sideToggle.setAttribute('aria-expanded', 'true');
                el.sideToggle.title = 'Свернуть параметры';
                el.sideToggle.querySelector('.side-toggle__icon').textContent = '›';
                el.download.classList.add('hidden');
                el.cancel.classList.add('hidden');
                el.body.innerHTML = '';
                el.head.innerHTML = '';
                el.chips.innerHTML = '';
                el.cap.classList.add('hidden');
                el.label.textContent = 'Выберите параметры справа и нажмите «Найти отличия».';
            })
            .catch(function (error) {
                setProgress(false, 0);
                showError(error);
            })
            .then(function () {
                setBusy(false);
                window.setTimeout(function () {
                    if (!running) setProgress(false, 0);
                }, 280);
            });
    }

    function renderSettings(data) {
        renderWarnings(data.warnings || []);
        var prep = data.prepare || {};
        if (el.scientificBox) {
            var showSci = !!(prep.scientificNotation);
            el.scientificBox.classList.toggle('hidden', !showSci);
            if (el.ignoreScientific && showSci) {
                el.ignoreScientific.checked = true;
            }
        }
        if (data.kind === 'text') {
            el.keys.innerHTML = '';
            el.textBox.classList.remove('hidden');
            el.positional.classList.add('hidden');
            if (el.scientificBox) el.scientificBox.classList.add('hidden');
            el.sheets.textContent = 'Текстовых блоков: ' + prep.blocksA + ' и ' + prep.blocksB + '. Модули: ' + data.modules.a + ' / ' + data.modules.b + '.';
            return;
        }
        el.textBox.classList.add('hidden');
        el.positional.classList.remove('hidden');
        var sheets = prep.sheets ? prep.sheets : [];
        var lines = sheets.map(function (sheet) {
            return sheet.nameA + ' (' + sheet.rowsA + ') → ' + sheet.nameB + ' (' + sheet.rowsB + ')';
        });
        el.sheets.textContent = lines.join('; ');
        var headers = sheets.length ? sheets[0].headers : [];
        var selected = {};
        var key = prep.key ? prep.key : { columns: [], reason: '' };
        (key.columns || []).forEach(function (index) { selected[index] = true; });
        var html = '<p class="muted">' + esc(key.reason || '') + '</p><div class="keys">';
        headers.forEach(function (header, index) {
            html += '<label class="key"><input type="checkbox" data-key="' + index + '"' + (selected[index] ? ' checked' : '') + '><span>' + esc(header) + '</span></label>';
        });
        html += '</div>';
        el.keys.innerHTML = html;
    }

    function onStart(positional) {
        if (!jobId) return;
        hideError();
        var body = { jobId: jobId };
        if (kind === 'text') {
            body.ignoreEmpty = document.getElementById('ignore-empty').checked;
            body.collapseWhitespace = document.getElementById('collapse-space').checked;
        } else if (positional) {
            body.keyColumns = [];
        } else {
            body.keyColumns = selectedKeys();
        }
        if (kind !== 'text' && el.ignoreScientific && el.scientificBox && !el.scientificBox.classList.contains('hidden')) {
            body.ignoreScientificNoise = el.ignoreScientific.checked;
        }
        setBusy(true);
        showRun();
        fetch('api.php?action=start', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        }).then(readJson).then(function () {
            running = true;
            return pump();
        }).catch(function (error) {
            running = false;
            setProgress(false, 0);
            showError(error);
        }).then(function () { setBusy(false); });
    }

    function pump() {
        if (!running) return Promise.resolve();
        return fetch('api.php?action=process', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ jobId: jobId })
        }).then(readJson).then(function (data) {
            applyChunk(data);
            if (data.status === 'done') {
                running = false;
                setProgress(false, 100);
                el.cancel.classList.add('hidden');
                el.download.classList.remove('hidden');
                return;
            }
            if (data.status === 'cancelled' || data.status === 'error') {
                running = false;
                setProgress(false, data.progress || 0);
                return;
            }
            return pump();
        });
    }

    function applyChunk(data) {
        setProgress(data.status !== 'done', data.progress || 0);
        el.label.textContent = 'Обработано ' + (data.progress || 0) + '%';
        renderChips(data.summary || {});
        renderWarnings(data.warnings || []);
        (data.rows || []).forEach(appendRow);
    }

    function appendRow(row) {
        if (kind === 'text') {
            appendFlatRow(row);
            return;
        }
        appendGroupedRow(row);
    }

    function appendFlatRow(row) {
        if (shown >= uiCap) {
            el.cap.classList.remove('hidden');
            el.cap.textContent = 'На экране первые ' + uiCap + ' отличий. Полный список — в Excel-отчёте.';
            return;
        }
        shown++;
        var status = row.status || '';
        var tr = document.createElement('tr');
        tr.className = 'row-' + status;
        var fieldTd = document.createElement('td');
        fieldTd.className = 'cell-field';
        fieldTd.appendChild(buildFieldCell(labelOf(status) || 'Блок', '', status));
        var wasTd = document.createElement('td');
        wasTd.className = 'cell-was';
        var becameTd = document.createElement('td');
        becameTd.className = 'cell-became';
        if (status === 'changed') {
            var parts = inlineTextDiff(row.before || '', row.after || '');
            wasTd.appendChild(parts.before);
            becameTd.appendChild(parts.after);
        } else if (status === 'removed') {
            wasTd.appendChild(wrapMarked(row.before || '', 'diff-del'));
        } else if (status === 'added') {
            becameTd.appendChild(wrapMarked(row.after || '', 'diff-add'));
        } else {
            wasTd.textContent = row.before || '';
            becameTd.textContent = row.after || '';
        }
        applyWidthToCells([fieldTd, wasTd, becameTd]);
        tr.appendChild(fieldTd);
        tr.appendChild(wasTd);
        tr.appendChild(becameTd);
        el.body.appendChild(tr);
        maybeAutofit();
    }

    function wrapMarked(text, cls) {
        var span = document.createElement('span');
        span.className = cls;
        span.textContent = text;
        return span;
    }

    function tokenizeText(text) {
        return String(text || '').match(/\s+|[^\s]+/g) || [];
    }

    function inlineTextDiff(before, after) {
        var a = tokenizeText(before);
        var b = tokenizeText(after);
        if (a.length * b.length > 120000) {
            return {
                before: wrapMarked(before, 'diff-del'),
                after: wrapMarked(after, 'diff-add')
            };
        }
        var n = a.length;
        var m = b.length;
        var dp = [];
        var i;
        var j;
        for (i = 0; i <= n; i++) {
            dp[i] = new Array(m + 1);
            for (j = 0; j <= m; j++) {
                dp[i][j] = 0;
            }
        }
        for (i = 1; i <= n; i++) {
            for (j = 1; j <= m; j++) {
                if (a[i - 1] === b[j - 1]) {
                    dp[i][j] = dp[i - 1][j - 1] + 1;
                } else {
                    dp[i][j] = dp[i - 1][j] > dp[i][j - 1] ? dp[i - 1][j] : dp[i][j - 1];
                }
            }
        }
        var ops = [];
        i = n;
        j = m;
        while (i > 0 || j > 0) {
            if (i > 0 && j > 0 && a[i - 1] === b[j - 1]) {
                ops.push({ t: 'eq', v: a[i - 1] });
                i--;
                j--;
            } else if (j > 0 && (i === 0 || dp[i][j - 1] >= dp[i - 1][j])) {
                ops.push({ t: 'add', v: b[j - 1] });
                j--;
            } else {
                ops.push({ t: 'del', v: a[i - 1] });
                i--;
            }
        }
        ops.reverse();
        var beforeWrap = document.createElement('span');
        var afterWrap = document.createElement('span');
        ops.forEach(function (op) {
            if (op.t === 'eq') {
                beforeWrap.appendChild(document.createTextNode(op.v));
                afterWrap.appendChild(document.createTextNode(op.v));
            } else if (op.t === 'del') {
                beforeWrap.appendChild(wrapMarked(op.v, 'diff-del'));
            } else if (op.t === 'add') {
                afterWrap.appendChild(wrapMarked(op.v, 'diff-add'));
            }
        });
        return { before: beforeWrap, after: afterWrap };
    }

    function appendGroupedRow(row) {
        var status = row.status || '';
        var groupKey = (row.sheet || '') + '\0' + (row.key || '') + '\0' + status;
        var group = groups[groupKey];
        if (!group) {
            if (Object.keys(groups).length >= uiCap) {
                el.cap.classList.remove('hidden');
                el.cap.textContent = 'На экране первые ' + uiCap + ' записей. Полный список — в Excel-отчёте.';
                return;
            }
            group = createGroup(row, status, groupKey);
            groups[groupKey] = group;
            shown++;
        }
        appendDetail(group, row);
        maybeAutofit();
    }

    function createGroup(row, status, groupKey) {
        groupSeq++;
        var id = 'g' + groupSeq;
        var title = row.keyTitle || row.key || '(без ключа)';
        var tr = document.createElement('tr');
        tr.className = 'row-group row-' + status;
        tr.dataset.group = id;
        var td = document.createElement('td');
        td.colSpan = 3;
        td.className = 'cell-group';
        var toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'group-toggle';
        toggle.setAttribute('aria-expanded', 'false');
        toggle.textContent = '›';
        toggle.addEventListener('click', function (event) {
            event.stopPropagation();
            toggleGroup(id, toggle);
        });
        var titleEl = document.createElement('span');
        titleEl.className = 'group-title';
        titleEl.textContent = title;
        var countEl = document.createElement('span');
        countEl.className = 'group-count';
        countEl.textContent = '0 полей';
        td.appendChild(toggle);
        td.appendChild(titleEl);
        var badgeText = badgeOf(status);
        if (badgeText) {
            var badge = document.createElement('span');
            badge.className = 'field-badge field-badge--' + status;
            badge.textContent = badgeText;
            td.appendChild(badge);
        }
        td.appendChild(countEl);
        tr.appendChild(td);
        tr.addEventListener('click', function () {
            toggleGroup(id, toggle);
        });
        el.body.appendChild(tr);
        return {
            id: id,
            key: groupKey,
            status: status,
            head: tr,
            countEl: countEl,
            count: 0,
            open: false
        };
    }

    function appendDetail(group, row) {
        group.count++;
        group.countEl.textContent = group.count + ' ' + pluralFields(group.count);
        var tr = document.createElement('tr');
        tr.className = 'row-detail row-' + (row.status || '') + (group.open ? '' : ' is-collapsed');
        tr.dataset.group = group.id;
        var fieldTd = document.createElement('td');
        fieldTd.className = 'cell-field';
        var title = document.createElement('div');
        title.className = 'field-cell__title';
        title.textContent = row.column || '(без имени)';
        fieldTd.appendChild(title);
        var wasTd = document.createElement('td');
        wasTd.className = 'cell-was';
        wasTd.textContent = row.before || '';
        var becameTd = document.createElement('td');
        becameTd.className = 'cell-became';
        becameTd.textContent = row.after || '';
        applyWidthToCells([fieldTd, wasTd, becameTd]);
        tr.appendChild(fieldTd);
        tr.appendChild(wasTd);
        tr.appendChild(becameTd);
        var anchor = group.head;
        while (anchor.nextSibling && anchor.nextSibling.getAttribute && anchor.nextSibling.getAttribute('data-group') === group.id) {
            anchor = anchor.nextSibling;
        }
        if (anchor.nextSibling) {
            el.body.insertBefore(tr, anchor.nextSibling);
        } else {
            el.body.appendChild(tr);
        }
    }

    function toggleGroup(id, toggle) {
        var open = toggle.getAttribute('aria-expanded') !== 'true';
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        toggle.classList.toggle('is-open', open);
        var details = el.body.querySelectorAll('tr.row-detail[data-group="' + id + '"]');
        for (var i = 0; i < details.length; i++) {
            details[i].classList.toggle('is-collapsed', !open);
        }
        var group;
        for (var key in groups) {
            if (groups[key].id === id) {
                group = groups[key];
                break;
            }
        }
        if (group) group.open = open;
    }

    function pluralFields(n) {
        var mod10 = n % 10;
        var mod100 = n % 100;
        if (mod10 === 1 && mod100 !== 11) return 'поле';
        if (mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14)) return 'поля';
        return 'полей';
    }

    function applyWidthToCells(cells) {
        cells.forEach(function (td, index) {
            if (colWidths[index]) {
                td.style.width = colWidths[index] + 'px';
                td.style.maxWidth = colWidths[index] + 'px';
            }
        });
    }

    function maybeAutofit() {
        if (shown === 1 || shown === 20 || shown === 80) {
            autofitColumns(true);
        }
    }

    function buildFieldCell(title, meta, status) {
        var wrap = document.createElement('div');
        wrap.className = 'field-cell';
        var main = document.createElement('div');
        main.className = 'field-cell__main';
        var titleEl = document.createElement('div');
        titleEl.className = 'field-cell__title';
        titleEl.textContent = title;
        main.appendChild(titleEl);
        if (meta) {
            var metaEl = document.createElement('div');
            metaEl.className = 'field-cell__meta';
            metaEl.textContent = meta;
            main.appendChild(metaEl);
        }
        wrap.appendChild(main);
        var badgeText = badgeOf(status);
        if (badgeText) {
            var badge = document.createElement('span');
            badge.className = 'field-badge field-badge--' + status;
            badge.textContent = badgeText;
            wrap.appendChild(badge);
        }
        return wrap;
    }

    function badgeOf(status) {
        var map = {
            added: 'добавлено',
            removed: 'удалено',
            changed: 'изменено',
            conflict: 'конфликт'
        };
        return map[status] || '';
    }

    function showRun() {
        shown = 0;
        colWidths = [];
        manualCols = {};
        groups = {};
        groupSeq = 0;
        el.body.innerHTML = '';
        el.cap.classList.add('hidden');
        el.download.classList.add('hidden');
        el.cancel.classList.remove('hidden');
        renderHeader(['Поле', 'Было', 'Стало']);
        autofitColumns(false);
        setProgress(true, 2);
        el.label.textContent = 'Обработка…';
    }

    function renderHeader(labels) {
        el.head.innerHTML = '';
        var tr = document.createElement('tr');
        labels.forEach(function (label, index) {
            var th = document.createElement('th');
            th.dataset.col = String(index);
            var span = document.createElement('span');
            span.className = 'th-label';
            span.textContent = label;
            th.appendChild(span);
            var handle = document.createElement('span');
            handle.className = 'col-resize';
            handle.title = 'Изменить ширину';
            handle.addEventListener('mousedown', function (event) {
                startColResize(event, index, th);
            });
            th.appendChild(handle);
            tr.appendChild(th);
        });
        el.head.appendChild(tr);
    }

    function measureText(text, font) {
        if (!measureCanvas) {
            measureCanvas = document.createElement('canvas').getContext('2d');
        }
        measureCanvas.font = font || '600 14px "Segoe UI", "Helvetica Neue", Arial, sans-serif';
        return Math.ceil(measureCanvas.measureText(String(text || '')).width);
    }

    function autofitColumns(fromContent) {
        var ths = el.head.querySelectorAll('th');
        if (!ths.length) return;
        var styles = window.getComputedStyle(ths[0]);
        var pad = (parseFloat(styles.paddingLeft) || 0) + (parseFloat(styles.paddingRight) || 0) + 18;
        var font = styles.fontWeight + ' ' + styles.fontSize + ' ' + styles.fontFamily;
        var bodyFont = '14px "Segoe UI", "Helvetica Neue", Arial, sans-serif';
        var rows = el.body.querySelectorAll('tr');
        var sample = Math.min(rows.length, 40);
        var widths = [];
        for (var i = 0; i < ths.length; i++) {
            if (manualCols[i] && colWidths[i]) {
                widths[i] = colWidths[i];
                continue;
            }
            var label = ths[i].querySelector('.th-label');
            var width = measureText(label ? label.textContent : '', font) + pad;
            if (fromContent) {
                for (var r = 0; r < sample; r++) {
                    var cell = rows[r].children[i];
                    if (!cell) continue;
                    var text = cell.textContent || '';
                    var line = text.split('\n')[0];
                    if (line.length > 48) line = line.slice(0, 48);
                    width = Math.max(width, measureText(line, bodyFont) + pad);
                }
            }
            if (i === 0) {
                width = Math.max(width, 200);
            } else {
                width = Math.max(width, 160);
            }
            width = Math.max(72, Math.min(width, 520));
            if (colWidths[i] && fromContent) {
                width = Math.max(colWidths[i], width);
            }
            widths[i] = width;
        }
        // Растягиваем «Было/Стало», чтобы таблица занимала всю ширину, как в образце.
        var wrap = el.grid.parentElement;
        var available = wrap ? wrap.clientWidth : 0;
        if (available > 0 && widths.length === 3) {
            var sum = widths[0] + widths[1] + widths[2];
            if (sum < available) {
                var extra = available - sum;
                widths[1] += Math.floor(extra / 2);
                widths[2] += extra - Math.floor(extra / 2);
            }
        }
        applyColWidths(widths);
    }

    function applyColWidths(widths) {
        colWidths = widths.slice();
        var ths = el.head.querySelectorAll('th');
        var total = 0;
        for (var i = 0; i < ths.length; i++) {
            var w = colWidths[i] || 80;
            total += w;
            ths[i].style.width = w + 'px';
            ths[i].style.minWidth = w + 'px';
            ths[i].style.maxWidth = w + 'px';
        }
        el.grid.style.width = Math.max(total, el.grid.parentElement ? el.grid.parentElement.clientWidth : total) + 'px';
        var rows = el.body.querySelectorAll('tr');
        for (var r = 0; r < rows.length; r++) {
            for (var c = 0; c < rows[r].children.length; c++) {
                if (!colWidths[c]) continue;
                rows[r].children[c].style.width = colWidths[c] + 'px';
                rows[r].children[c].style.maxWidth = colWidths[c] + 'px';
            }
        }
    }

    function startColResize(event, index, th) {
        event.preventDefault();
        event.stopPropagation();
        var startX = event.clientX;
        var startWidth = colWidths[index] || th.getBoundingClientRect().width;
        var handle = th.querySelector('.col-resize');
        if (handle) handle.classList.add('is-active');
        document.body.classList.add('is-col-resizing');

        function onMove(moveEvent) {
            var next = Math.max(48, Math.round(startWidth + (moveEvent.clientX - startX)));
            colWidths[index] = next;
            applyColWidths(colWidths);
        }

        function onUp() {
            manualCols[index] = true;
            document.removeEventListener('mousemove', onMove);
            document.removeEventListener('mouseup', onUp);
            document.body.classList.remove('is-col-resizing');
            if (handle) handle.classList.remove('is-active');
        }

        document.addEventListener('mousemove', onMove);
        document.addEventListener('mouseup', onUp);
    }

    function renderChips(summary) {
        el.chips.innerHTML =
            chip('chip-add', '+' + (summary.added || 0)) +
            chip('chip-del', '−' + (summary.removed || 0)) +
            chip('chip-chg', '~' + (summary.changed || 0)) +
            (summary.conflicts ? chip('chip-conf', 'конфликт ' + summary.conflicts) : '');
    }

    function chip(cls, text) {
        return '<span class="chip ' + cls + '">' + esc(text) + '</span>';
    }

    function renderWarnings(list) {
        if (!list || !list.length) {
            el.warnings.classList.add('hidden');
            el.warnings.innerHTML = '';
            return;
        }
        el.warnings.classList.remove('hidden');
        el.warnings.innerHTML = list.map(function (item) { return '<div>' + esc(item) + '</div>'; }).join('');
    }

    function selectedKeys() {
        var boxes = el.keys.querySelectorAll('input[type="checkbox"]');
        var values = [];
        for (var i = 0; i < boxes.length; i++) {
            if (boxes[i].checked) values.push(parseInt(boxes[i].getAttribute('data-key'), 10));
        }
        return values;
    }

    function onCancel() {
        running = false;
        if (!jobId) return;
        fetch('api.php?action=cancel', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ jobId: jobId })
        }).then(readJson).catch(showError);
        setProgress(false, 0);
        el.label.textContent = 'Отменено';
        el.cancel.classList.add('hidden');
    }

    function downloadReport() {
        if (!jobId) return;
        window.location = 'api.php?action=report&jobId=' + encodeURIComponent(jobId);
    }

    function resetAll() {
        running = false;
        jobId = null;
        kind = null;
        fileA = null;
        fileB = null;
        el.fileA.value = '';
        el.fileB.value = '';
        el.nameA.textContent = 'Файл не выбран';
        el.nameB.textContent = 'Файл не выбран';
        el.nameA.title = '';
        el.nameB.title = '';
        el.workspace.classList.add('hidden');
        el.workspace.classList.remove('is-side-collapsed');
        setProgress(false, 0);
        hideError();
    }

    function setProgress(active, value) {
        var pct = Math.max(0, Math.min(100, Math.round(value || 0)));
        el.progress.classList.toggle('is-active', !!active);
        el.bar.style.width = pct + '%';
        if (el.pct) {
            el.pct.textContent = pct + '%';
        }
        el.progress.setAttribute('aria-hidden', active ? 'false' : 'true');
    }

    function setBusy(busy, prepareLabel) {
        el.prepare.disabled = busy;
        el.start.disabled = busy;
        el.positional.disabled = busy;
        el.prepare.textContent = busy ? (prepareLabel || 'Сравнение…') : 'Сравнить';
    }

    function readJson(response) {
        return response.text().then(function (text) {
            var data;
            try {
                data = JSON.parse(text);
            } catch (e) {
                throw new Error('Сервер вернул не JSON. Проверьте upload_max_filesize и post_max_size.');
            }
            if (!data.ok) throw new Error(data.error || 'Ошибка');
            return data;
        });
    }

    function labelOf(status) {
        var map = { added: 'Добавлено', removed: 'Удалено', changed: 'Изменено', conflict: 'Конфликт ключа' };
        return map[status] || status || '';
    }

    function showError(error) {
        el.error.classList.remove('hidden');
        el.error.textContent = error && error.message ? error.message : String(error);
    }

    function hideError() {
        el.error.classList.add('hidden');
        el.error.textContent = '';
    }

    function esc(value) {
        return String(value).replace(/[&<>"']/g, function (char) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char];
        });
    }
})();
