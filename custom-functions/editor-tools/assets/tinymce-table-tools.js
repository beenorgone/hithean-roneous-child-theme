(function () {
    if (typeof tinymce === 'undefined') {
        return;
    }

    tinymce.PluginManager.add('ivar_table_tools', function (editor) {
        var tableClass = 'ivar-content-table';
        var colorPresets = [
            { text: 'Đen', value: '#202124' },
            { text: 'Xám', value: '#5f6368' },
            { text: 'Đỏ', value: '#d93025' },
            { text: 'Cam', value: '#e8710a' },
            { text: 'Xanh lá', value: '#188038' },
            { text: 'Xanh dương', value: '#1a73e8' }
        ];
        var backgroundPresets = [
            { text: 'Vàng nhạt', value: '#fff7cc' },
            { text: 'Xanh lá nhạt', value: '#e6f4ea' },
            { text: 'Xanh dương nhạt', value: '#e8f0fe' },
            { text: 'Đỏ nhạt', value: '#fce8e6' },
            { text: 'Xám nhạt', value: '#f1f3f4' },
            { text: 'Trong suốt', value: '' }
        ];
        var fontFamilyPresets = [
            { text: 'Mặc định', value: '' },
            { text: 'System UI', value: 'system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif' },
            { text: 'Arial', value: 'Arial, Helvetica, sans-serif' },
            { text: 'Helvetica', value: 'Helvetica, Arial, sans-serif' },
            { text: 'Times New Roman', value: '"Times New Roman", Times, serif' },
            { text: 'Georgia', value: 'Georgia, serif' },
            { text: 'Courier New', value: '"Courier New", Courier, monospace' }
        ];
        var fontSizePresets = [
            { text: 'Mặc định', value: '' },
            { text: 'Nhỏ - 13px', value: '13px' },
            { text: 'Thường - 16px', value: '16px' },
            { text: 'Lớn - 18px', value: '18px' },
            { text: 'Tiêu đề nhỏ - 22px', value: '22px' },
            { text: 'Tiêu đề - 28px', value: '28px' },
            { text: 'Tiêu đề lớn - 36px', value: '36px' }
        ];
        var fontWeightPresets = [
            { text: 'Mặc định', value: '' },
            { text: 'Regular', value: '400' },
            { text: 'Medium', value: '500' },
            { text: 'Semi-bold', value: '600' },
            { text: 'Bold', value: '700' }
        ];
        var fontStylePresets = [
            { text: 'Mặc định', value: '' },
            { text: 'Bình thường', value: 'normal' },
            { text: 'Nghiêng', value: 'italic' }
        ];
        var buttonPresets = [
            {
                text: 'Material Primary',
                className: 'ivar-content-button ivar-content-button--primary',
                styles: {
                    color: '#ffffff',
                    backgroundColor: '#6200ee',
                    borderColor: '#6200ee',
                    borderWidth: '0',
                    borderStyle: 'solid',
                    borderRadius: '4px',
                    padding: '6px 16px',
                    margin: '0 0 10px 0',
                    fontSize: '13px',
                    fontWeight: '600',
                    textTransform: 'uppercase',
                    letterSpacing: '0.5px',
                    boxShadow: '0 1px 3px rgba(0,0,0,.12), 0 1px 2px rgba(0,0,0,.24)'
                }
            },
            {
                text: 'Material Secondary',
                className: 'ivar-content-button ivar-content-button--secondary',
                styles: {
                    color: '#6200ee',
                    backgroundColor: '#ffffff',
                    borderColor: '#ffffff',
                    borderWidth: '0',
                    borderStyle: 'solid',
                    borderRadius: '4px',
                    padding: '6px 16px',
                    margin: '0 0 10px 0',
                    fontSize: '13px',
                    fontWeight: '600',
                    textTransform: 'uppercase',
                    letterSpacing: '0.5px',
                    boxShadow: '0 1px 3px rgba(0,0,0,.12), 0 1px 2px rgba(0,0,0,.24)'
                }
            },
            {
                text: 'Viền xanh',
                className: 'ivar-content-button ivar-content-button--outline',
                styles: {
                    color: '#6200ee',
                    backgroundColor: '#ffffff',
                    borderColor: '#6200ee',
                    borderWidth: '1px',
                    borderStyle: 'solid',
                    borderRadius: '4px',
                    padding: '6px 16px',
                    margin: '0 0 10px 0',
                    fontSize: '13px',
                    fontWeight: '600',
                    textTransform: 'uppercase',
                    letterSpacing: '0.5px',
                    boxShadow: 'none'
                }
            },
            {
                text: 'Xám',
                className: 'ivar-content-button ivar-content-button--neutral',
                styles: {
                    color: '#202124',
                    backgroundColor: '#f1f3f4',
                    borderColor: '#dadce0',
                    borderWidth: '1px',
                    borderStyle: 'solid',
                    borderRadius: '4px',
                    padding: '6px 16px',
                    margin: '0 0 10px 0',
                    fontSize: '13px',
                    fontWeight: '600',
                    textTransform: 'uppercase',
                    letterSpacing: '0.5px',
                    boxShadow: '0 1px 3px rgba(0,0,0,.12), 0 1px 2px rgba(0,0,0,.24)'
                }
            }
        ];
        var adminCssButtonPresets = [
            {
                text: 'Admin: Primary',
                className: 'ivar-content-button btn btn-primary',
                cssOnly: true,
                styles: {
                    color: '#ffffff',
                    backgroundColor: '#6200ee',
                    borderColor: '#6200ee',
                    borderWidth: '0',
                    borderStyle: 'solid',
                    borderRadius: '4px',
                    padding: '6px 16px',
                    margin: '0 0 10px 0',
                    fontSize: '13px',
                    fontWeight: '600',
                    textTransform: 'uppercase',
                    letterSpacing: '0.5px',
                    boxShadow: '0 1px 3px rgba(0,0,0,.12), 0 1px 2px rgba(0,0,0,.24)'
                }
            },
            {
                text: 'Admin: Secondary',
                className: 'ivar-content-button btn button-secondary',
                cssOnly: true,
                styles: {
                    color: '#6200ee',
                    backgroundColor: '#ffffff',
                    borderColor: '#ffffff',
                    borderWidth: '0',
                    borderStyle: 'solid',
                    borderRadius: '4px',
                    padding: '6px 16px',
                    margin: '0 0 10px 0',
                    fontSize: '13px',
                    fontWeight: '600',
                    textTransform: 'uppercase',
                    letterSpacing: '0.5px',
                    boxShadow: '0 1px 3px rgba(0,0,0,.12), 0 1px 2px rgba(0,0,0,.24)'
                }
            },
            {
                text: 'Admin: WP Primary',
                className: 'ivar-content-button button button-primary',
                cssOnly: true,
                styles: {
                    color: '#ffffff',
                    backgroundColor: '#6200ee',
                    borderColor: '#6200ee',
                    borderWidth: '0',
                    borderStyle: 'solid',
                    borderRadius: '4px',
                    padding: '6px 16px',
                    margin: '0 0 10px 0',
                    fontSize: '13px',
                    fontWeight: '600',
                    textTransform: 'uppercase',
                    letterSpacing: '0.5px',
                    boxShadow: '0 1px 3px rgba(0,0,0,.12), 0 1px 2px rgba(0,0,0,.24)'
                }
            }
        ];
        var customCssButtonPresets = [
            { text: 'Custom: Dark Blue Reverse', className: 'ivar-content-button button--dark-blue-reverse', cssOnly: true },
            { text: 'Custom: Light Blue', className: 'ivar-content-button button--light-blue', cssOnly: true },
            { text: 'Custom: Nước ép ký tự', className: 'ivar-content-button button--nuocepkytu-light-green', cssOnly: true },
            { text: 'Custom: Protein Yeast Yellow', className: 'ivar-content-button button--protein-yeast-yellow', cssOnly: true },
            { text: 'Custom: Protein Yeast Brown', className: 'ivar-content-button button--protein-yeast-brown', cssOnly: true },
            { text: 'Custom: Short Text', className: 'ivar-content-button button--dark-blue-reverse button--short-text', cssOnly: true },
            { text: 'Custom: Small', className: 'ivar-content-button button--dark-blue-reverse button--small', cssOnly: true },
            { text: 'Custom: None', className: 'ivar-content-button button--none', cssOnly: true }
        ].map(function (preset) {
            preset.styles = {
                color: '#ffffff',
                backgroundColor: '#5aa7d8',
                borderColor: '#5aa7d8',
                borderWidth: '2px',
                borderStyle: 'solid',
                borderRadius: '0',
                padding: '0 20px',
                margin: '0 0 10px 0',
                fontSize: '16px',
                fontWeight: '500',
                textTransform: 'uppercase',
                letterSpacing: '1px',
                boxShadow: 'none'
            };
            return preset;
        });

        function repeat(count, callback) {
            var output = '';
            for (var index = 0; index < count; index += 1) {
                output += callback(index);
            }
            return output;
        }

        function getCell() {
            return editor.dom.getParent(editor.selection.getNode(), 'td,th');
        }

        function getRow() {
            return editor.dom.getParent(editor.selection.getNode(), 'tr');
        }

        function getTable() {
            return editor.dom.getParent(editor.selection.getNode(), 'table');
        }

        function normalizeCount(value, fallback, max) {
            var number = parseInt(value, 10);
            if (isNaN(number) || number < 1) {
                return fallback;
            }

            return Math.min(number, max);
        }

        function normalizeColor(value) {
            var color = (value || '').trim();
            if (color === '') {
                return '';
            }

            if (/^#[0-9a-f]{3}([0-9a-f]{3})?$/i.test(color)) {
                return color;
            }

            window.alert('Mã màu không hợp lệ. Dùng dạng #RGB hoặc #RRGGBB.');
            return null;
        }

        function normalizeFontSize(value) {
            var size = (value || '').trim();
            if (size === '') {
                return '';
            }

            if (/^(?:[1-9][0-9]?|1[0-4][0-9]|150)(?:px|%)$/.test(size) || /^(?:0\.[5-9]|1(?:\.[0-9])?|2(?:\.0)?)(?:em|rem)$/.test(size)) {
                return size;
            }

            window.alert('Cỡ chữ không hợp lệ. Dùng 8px-150px, %, em hoặc rem.');
            return null;
        }

        function normalizeFontFamily(value) {
            var family = (value || '').trim();
            if (family === '') {
                return '';
            }

            if (/^[a-z0-9\s"',\-.]+$/i.test(family)) {
                return family;
            }

            window.alert('Font family không hợp lệ.');
            return null;
        }

        function escapeHtml(value) {
            return String(value || '').replace(/[&<>"']/g, function (character) {
                return {
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#039;'
                }[character];
            });
        }

        function normalizeButtonUrl(value) {
            var url = (value || '').trim();
            if (url === '') {
                return '';
            }

            if (/^(https?:\/\/|\/|#|mailto:|tel:)/i.test(url)) {
                return url;
            }

            window.alert('URL không hợp lệ. Dùng https://, /duong-dan, #anchor, mailto: hoặc tel:.');
            return null;
        }

        function normalizeButtonClasses(value) {
            var classes = (value || '').trim();
            var parts;

            if (classes === '') {
                return 'ivar-content-button button';
            }

            parts = classes.split(/\s+/).filter(function (className) {
                return /^[a-z0-9_-]+$/i.test(className);
            });

            if (parts.indexOf('ivar-content-button') === -1) {
                parts.unshift('ivar-content-button');
            }

            return parts.join(' ');
        }

        function normalizeCssLength(value, fallback, allowAuto) {
            var length = (value || '').trim();
            if (length === '') {
                return fallback || '';
            }

            if (allowAuto && length === 'auto') {
                return length;
            }

            if (/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:px|em|rem|%|vh|vw)?$/.test(length)) {
                return length;
            }

            return fallback || '';
        }

        function normalizeCssSpacing(value, fallback) {
            var spacing = (value || '').trim();
            var parts;

            if (spacing === '') {
                return fallback || '';
            }

            parts = spacing.split(/\s+/);
            if (parts.length > 4) {
                return fallback || '';
            }

            if (parts.every(function (part) {
                return normalizeCssLength(part, '', true) !== '';
            })) {
                return parts.join(' ');
            }

            return fallback || '';
        }

        function normalizeBorderStyle(value, fallback) {
            var style = (value || '').trim();
            if (/^(none|solid|dashed|dotted|double)$/.test(style)) {
                return style;
            }

            return fallback || 'solid';
        }

        function normalizeButtonTextTransform(value, fallback) {
            var transform = (value || '').trim();
            if (/^(none|uppercase|lowercase|capitalize)$/.test(transform)) {
                return transform;
            }

            return fallback || 'uppercase';
        }

        function normalizeButtonFontWeight(value, fallback) {
            var weight = (value || '').trim();
            if (/^(normal|bold|[1-9]00)$/.test(weight)) {
                return weight;
            }

            return fallback || '600';
        }

        function buttonStyleString(styles) {
            return [
                'display:inline-flex',
                'align-items:center',
                'justify-content:center',
                'height:auto',
                'line-height:20px',
                'padding:' + styles.padding,
                'margin:' + styles.margin,
                'border:' + styles.borderWidth + ' ' + styles.borderStyle + ' ' + styles.borderColor,
                'border-radius:' + styles.borderRadius,
                'background-color:' + styles.backgroundColor,
                'color:' + styles.color,
                'font-size:' + styles.fontSize,
                'font-weight:' + styles.fontWeight,
                'text-transform:' + styles.textTransform,
                'letter-spacing:' + styles.letterSpacing,
                'text-decoration:none',
                'box-shadow:' + styles.boxShadow,
                'cursor:pointer'
            ].join(';');
        }

        function cssPropertyName(property) {
            return property.replace(/[A-Z]/g, function (letter) {
                return '-' + letter.toLowerCase();
            });
        }

        function defaultInlineStyleValue(property) {
            if (property === 'backgroundColor') {
                return 'transparent';
            }

            return 'inherit';
        }

        function applySelectedTextStyle(property, value) {
            var selectedHtml = editor.selection.getContent({ format: 'html' });
            var selectedText = editor.selection.getContent({ format: 'text' });
            var styleValue = value || defaultInlineStyleValue(property);

            if (!selectedText.trim()) {
                window.alert('Vui lòng chọn phần chữ cần định dạng trước.');
                return;
            }

            editor.insertContent(
                '<span style="' + escapeHtml(cssPropertyName(property)) + ':' + escapeHtml(styleValue) + ';">' +
                selectedHtml +
                '</span>'
            );

            editor.nodeChanged();
        }

        function promptColor(property, label, fallback) {
            var color = normalizeColor(window.prompt(label, fallback));
            if (color === null) {
                return;
            }

            applySelectedTextStyle(property, color);
        }

        function buildColorMenu(property, customLabel, fallback, presets) {
            var menu = presets.map(function (preset) {
                return {
                    text: preset.text,
                    onclick: function () {
                        applySelectedTextStyle(property, preset.value);
                    }
                };
            });

            menu.push({
                text: 'Tùy chỉnh...',
                onclick: function () {
                    promptColor(property, customLabel, fallback);
                }
            });

            return menu;
        }

        function buildStyleMenu(property, presets) {
            return presets.map(function (preset) {
                return {
                    text: preset.text,
                    onclick: function () {
                        applySelectedTextStyle(property, preset.value);
                    }
                };
            });
        }

        function buildFontSizeMenu() {
            var menu = buildStyleMenu('fontSize', fontSizePresets);

            menu.push({
                text: 'Tùy chỉnh...',
                onclick: function () {
                    var size = normalizeFontSize(window.prompt('Cỡ chữ? Ví dụ: 16px, 1.1em, 120%', '18px'));
                    if (size !== null) {
                        applySelectedTextStyle('fontSize', size);
                    }
                }
            });

            return menu;
        }

        function buildFontFamilyMenu() {
            var menu = buildStyleMenu('fontFamily', fontFamilyPresets);

            menu.push({
                text: 'Tùy chỉnh...',
                onclick: function () {
                    var family = normalizeFontFamily(window.prompt('Font family? Ví dụ: Arial, sans-serif', 'Arial, sans-serif'));
                    if (family !== null) {
                        applySelectedTextStyle('fontFamily', family);
                    }
                }
            });

            return menu;
        }

        function selectedButton() {
            var node = editor.selection.getNode();
            var button = editor.dom.getParent(node, 'a.ivar-content-button');

            return button || null;
        }

        function currentButtonStyle(currentButton, preset, property, cssProperty) {
            if (currentButton) {
                return currentButton.style[cssProperty || property] || preset.styles[property] || '';
            }

            return preset.styles[property] || '';
        }

        function openButtonDialog(preset, currentButton, defaults) {
            var dialogData = {
                text: defaults.text,
                url: defaults.url,
                newTab: defaults.newTab,
                color: currentButtonStyle(currentButton, preset, 'color'),
                backgroundColor: currentButtonStyle(currentButton, preset, 'backgroundColor'),
                borderColor: currentButtonStyle(currentButton, preset, 'borderColor'),
                borderWidth: currentButtonStyle(currentButton, preset, 'borderWidth'),
                borderStyle: currentButtonStyle(currentButton, preset, 'borderStyle'),
                borderRadius: currentButtonStyle(currentButton, preset, 'borderRadius'),
                padding: currentButtonStyle(currentButton, preset, 'padding'),
                margin: currentButtonStyle(currentButton, preset, 'margin'),
                fontSize: currentButtonStyle(currentButton, preset, 'fontSize'),
                fontWeight: currentButtonStyle(currentButton, preset, 'fontWeight'),
                textTransform: currentButtonStyle(currentButton, preset, 'textTransform'),
                letterSpacing: currentButtonStyle(currentButton, preset, 'letterSpacing'),
                boxShadow: currentButtonStyle(currentButton, preset, 'boxShadow')
            };
            dialogData.useCssClassOnly = currentButton
                ? !currentButton.getAttribute('style')
                : !!preset.cssOnly;

            editor.windowManager.open({
                title: currentButton ? 'Sửa button' : 'Chèn button',
                body: [
                    { type: 'textbox', name: 'text', label: 'Nội dung' },
                    { type: 'textbox', name: 'url', label: 'URL' },
                    { type: 'checkbox', name: 'newTab', label: 'Mở tab mới' },
                    { type: 'textbox', name: 'className', label: 'CSS classes' },
                    { type: 'checkbox', name: 'useCssClassOnly', label: 'Chỉ dùng class CSS có sẵn' },
                    { type: 'textbox', name: 'color', label: 'Màu chữ' },
                    { type: 'textbox', name: 'backgroundColor', label: 'Màu nền' },
                    { type: 'textbox', name: 'borderColor', label: 'Màu viền' },
                    { type: 'textbox', name: 'borderWidth', label: 'Độ dày viền' },
                    {
                        type: 'listbox',
                        name: 'borderStyle',
                        label: 'Kiểu viền',
                        values: [
                            { text: 'None', value: 'none' },
                            { text: 'Solid', value: 'solid' },
                            { text: 'Dashed', value: 'dashed' },
                            { text: 'Dotted', value: 'dotted' },
                            { text: 'Double', value: 'double' }
                        ]
                    },
                    { type: 'textbox', name: 'borderRadius', label: 'Bo góc' },
                    { type: 'textbox', name: 'padding', label: 'Padding' },
                    { type: 'textbox', name: 'margin', label: 'Margin' },
                    { type: 'textbox', name: 'fontSize', label: 'Cỡ chữ' },
                    {
                        type: 'listbox',
                        name: 'fontWeight',
                        label: 'Độ đậm',
                        values: [
                            { text: 'Normal', value: 'normal' },
                            { text: '500', value: '500' },
                            { text: '600', value: '600' },
                            { text: 'Bold', value: 'bold' }
                        ]
                    },
                    {
                        type: 'listbox',
                        name: 'textTransform',
                        label: 'Chữ hoa/thường',
                        values: [
                            { text: 'None', value: 'none' },
                            { text: 'Uppercase', value: 'uppercase' },
                            { text: 'Lowercase', value: 'lowercase' },
                            { text: 'Capitalize', value: 'capitalize' }
                        ]
                    },
                    { type: 'textbox', name: 'letterSpacing', label: 'Letter spacing' },
                    { type: 'textbox', name: 'boxShadow', label: 'Box shadow' }
                ],
                data: Object.assign({ className: currentButton ? currentButton.getAttribute('class') : preset.className }, dialogData),
                onsubmit: function (event) {
                    submitButtonDialog(preset, currentButton, event.data);
                }
            });
        }

        function submitButtonDialog(preset, currentButton, data) {
            var url = normalizeButtonUrl(data.url);
            var styles;
            var attrs;
            var text;

            if (url === null) {
                return;
            }

            styles = {
                color: normalizeColor(data.color) || preset.styles.color,
                backgroundColor: normalizeColor(data.backgroundColor) || preset.styles.backgroundColor,
                borderColor: normalizeColor(data.borderColor) || preset.styles.borderColor,
                borderWidth: normalizeCssLength(data.borderWidth, preset.styles.borderWidth),
                borderStyle: normalizeBorderStyle(data.borderStyle, preset.styles.borderStyle),
                borderRadius: normalizeCssLength(data.borderRadius, preset.styles.borderRadius),
                padding: normalizeCssSpacing(data.padding, preset.styles.padding),
                margin: normalizeCssSpacing(data.margin, preset.styles.margin),
                fontSize: normalizeFontSize(data.fontSize) || preset.styles.fontSize,
                fontWeight: normalizeButtonFontWeight(data.fontWeight, preset.styles.fontWeight),
                textTransform: normalizeButtonTextTransform(data.textTransform, preset.styles.textTransform),
                letterSpacing: normalizeCssLength(data.letterSpacing, preset.styles.letterSpacing),
                boxShadow: (data.boxShadow || preset.styles.boxShadow).trim()
            };
            attrs = {
                href: url || '#',
                class: normalizeButtonClasses(data.className || preset.className),
                style: data.useCssClassOnly ? '' : buttonStyleString(styles),
                target: data.newTab ? '_blank' : '',
                rel: data.newTab ? 'noopener noreferrer' : ''
            };
            text = (data.text || '').trim() || 'Xem thêm';

            if (currentButton) {
                currentButton.textContent = text;
                editor.dom.setAttribs(currentButton, attrs);
                editor.selection.select(currentButton);
                editor.nodeChanged();
                return;
            }

            editor.insertContent(
                '<a href="' + escapeHtml(attrs.href) + '" class="' + escapeHtml(attrs.class) + '"' +
                (attrs.style ? ' style="' + escapeHtml(attrs.style) + '"' : '') +
                (attrs.target ? ' target="' + escapeHtml(attrs.target) + '"' : '') +
                (attrs.rel ? ' rel="' + escapeHtml(attrs.rel) + '"' : '') +
                '>' + escapeHtml(text) + '</a>'
            );
        }

        function insertOrUpdateButton(preset) {
            var currentButton = selectedButton();
            var selectedText = editor.selection.getContent({ format: 'text' });
            var currentText = currentButton ? currentButton.textContent : selectedText;
            var currentUrl = currentButton ? currentButton.getAttribute('href') : '';
            var currentTarget = currentButton ? currentButton.getAttribute('target') : '';

            openButtonDialog(preset, currentButton, {
                text: currentText || 'Xem thêm',
                url: currentUrl || '#',
                newTab: currentTarget === '_blank'
            });
        }

        function buildButtonMenu() {
            return buttonPresets.concat(adminCssButtonPresets, customCssButtonPresets).map(function (preset) {
                return {
                    text: preset.text,
                    onclick: function () {
                        insertOrUpdateButton(preset);
                    }
                };
            });
        }

        function insertTable(rows, cols) {
            var header = '<thead><tr>' + repeat(cols, function () {
                return '<th scope="col">Tiêu đề</th>';
            }) + '</tr></thead>';
            var body = '<tbody>' + repeat(rows, function () {
                return '<tr>' + repeat(cols, function () {
                    return '<td>Nội dung</td>';
                }) + '</tr>';
            }) + '</tbody>';

            editor.insertContent('<table class="' + tableClass + '">' + header + body + '</table><p></p>');
        }

        function insertCustomTable() {
            var rows = normalizeCount(window.prompt('Số dòng nội dung?', '3'), 3, 50);
            var cols = normalizeCount(window.prompt('Số cột?', '3'), 3, 20);

            insertTable(rows, cols);
        }

        function addRowAfter() {
            var row = getRow();
            var table = getTable();
            if (!row || !table) {
                insertTable(3, 3);
                return;
            }

            var cells = row.children.length || 1;
            var newRow = editor.getDoc().createElement('tr');
            newRow.innerHTML = repeat(cells, function () {
                return '<td>Nội dung</td>';
            });

            row.parentNode.insertBefore(newRow, row.nextSibling);
            editor.nodeChanged();
        }

        function getCellIndex(cell) {
            var cells = Array.prototype.slice.call(cell.parentNode.children);
            return cells.indexOf(cell);
        }

        function addColumnAfter() {
            var cell = getCell();
            var table = getTable();
            if (!cell || !table) {
                insertTable(3, 3);
                return;
            }

            var index = getCellIndex(cell);
            Array.prototype.forEach.call(table.querySelectorAll('tr'), function (row) {
                var referenceCell = row.children[index] || row.lastElementChild;
                var newCell = editor.getDoc().createElement(referenceCell && referenceCell.tagName === 'TH' ? 'th' : 'td');
                newCell.innerHTML = referenceCell && referenceCell.tagName === 'TH' ? 'Tiêu đề' : 'Nội dung';

                if (referenceCell && referenceCell.nextSibling) {
                    row.insertBefore(newCell, referenceCell.nextSibling);
                } else {
                    row.appendChild(newCell);
                }
            });

            editor.nodeChanged();
        }

        function deleteRow() {
            var row = getRow();
            var table = getTable();
            if (!row || !table) {
                return;
            }

            row.parentNode.removeChild(row);
            if (!table.querySelector('tr')) {
                table.parentNode.removeChild(table);
            }

            editor.nodeChanged();
        }

        function deleteColumn() {
            var cell = getCell();
            var table = getTable();
            if (!cell || !table) {
                return;
            }

            var index = getCellIndex(cell);
            Array.prototype.forEach.call(table.querySelectorAll('tr'), function (row) {
                if (row.children[index]) {
                    row.removeChild(row.children[index]);
                }
            });

            if (!table.querySelector('td,th')) {
                table.parentNode.removeChild(table);
            }

            editor.nodeChanged();
        }

        function deleteTable() {
            var table = getTable();
            if (!table) {
                return;
            }

            table.parentNode.removeChild(table);
            editor.nodeChanged();
        }

        editor.addButton('ivartable', {
            type: 'menubutton',
            text: 'Bảng',
            tooltip: 'Chèn bảng',
            menu: [
                { text: '2 x 2', onclick: function () { insertTable(2, 2); } },
                { text: '3 x 3', onclick: function () { insertTable(3, 3); } },
                { text: '4 x 4', onclick: function () { insertTable(4, 4); } },
                { text: 'Tùy chỉnh...', onclick: insertCustomTable }
            ]
        });

        editor.addButton('ivarptextcolor', {
            type: 'menubutton',
            text: 'Màu chữ',
            tooltip: 'Đổi màu chữ cho đoạn đang chọn',
            menu: buildColorMenu('color', 'Mã màu chữ?', '#1a73e8', colorPresets)
        });

        editor.addButton('ivarpbackground', {
            type: 'menubutton',
            text: 'Nền chữ',
            tooltip: 'Đổi màu nền cho đoạn đang chọn',
            menu: buildColorMenu('backgroundColor', 'Mã màu nền?', '#fff7cc', backgroundPresets)
        });

        editor.addButton('ivarpfontfamily', {
            type: 'menubutton',
            text: 'Font',
            tooltip: 'Đổi font cho đoạn đang chọn',
            menu: buildFontFamilyMenu()
        });

        editor.addButton('ivarpfontsize', {
            type: 'menubutton',
            text: 'Cỡ chữ',
            tooltip: 'Đổi cỡ chữ cho đoạn đang chọn',
            menu: buildFontSizeMenu()
        });

        editor.addButton('ivarpfontweight', {
            type: 'menubutton',
            text: 'Độ đậm',
            tooltip: 'Đổi độ đậm cho đoạn đang chọn',
            menu: buildStyleMenu('fontWeight', fontWeightPresets)
        });

        editor.addButton('ivarpfontstyle', {
            type: 'menubutton',
            text: 'Kiểu chữ',
            tooltip: 'Đổi kiểu chữ cho đoạn đang chọn',
            menu: buildStyleMenu('fontStyle', fontStylePresets)
        });

        editor.addButton('ivarbutton', {
            type: 'menubutton',
            text: 'Button',
            tooltip: 'Chèn hoặc sửa button link',
            menu: buildButtonMenu()
        });

        editor.addButton('ivartableaddrow', {
            text: '+Dòng',
            tooltip: 'Thêm dòng sau dòng hiện tại',
            onclick: addRowAfter
        });

        editor.addButton('ivartableaddcol', {
            text: '+Cột',
            tooltip: 'Thêm cột sau cột hiện tại',
            onclick: addColumnAfter
        });

        editor.addButton('ivartabledeleterow', {
            text: '-Dòng',
            tooltip: 'Xóa dòng hiện tại',
            onclick: deleteRow
        });

        editor.addButton('ivartabledeletecol', {
            text: '-Cột',
            tooltip: 'Xóa cột hiện tại',
            onclick: deleteColumn
        });

        editor.addButton('ivartabledelete', {
            text: 'Xóa bảng',
            tooltip: 'Xóa toàn bộ bảng hiện tại',
            onclick: deleteTable
        });
    });
}());
