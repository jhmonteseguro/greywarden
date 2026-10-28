/**
 * Greywarden UI behaviour (vanilla JS, no build step).
 *
 *  - [data-gw-toggle="sidebar"]         toggles the mobile drawer (body.gw-sidebar-open); Esc closes it
 *  - [data-gw-collapse="sidebar"]       collapses the desktop sidebar to an icon rail
 *                                       (html.gw-sidebar-collapsed, remembered in localStorage "gw.sidebar")
 *  - "/" key                            focuses the global search (#gw-global-search)
 *  - [data-gw-clock]                    live UTC clock in the top bar
 *  - yii.confirm                        data-confirm links use a styled Bootstrap modal
 *  - .gw-grid__body.is-scrollable      set when a table is wider than its container
 *  - tooltips                           Bootstrap tooltips for heatmap cells, icon buttons and titled badges
 *  - [data-gw-depends][data-gw-value]  shows a block only when the referenced select/checkbox matches
 *  - [data-gw-check-types][data-gw-check-ownership]  device form: shows a device check only when it applies
 *  - [data-gw-cloud-types]  cloud asset form: shows a cloud check only when it applies to the resource type
 *  - [data-gw-score]                    live L x I score preview on the risk form
 *  - [data-gw-countdown]                live countdown to an ISO deadline (regulator 72h)
 *  - #hash on view pages                opens the matching Bootstrap tab (e.g. #tab-history)
 *  - select[multiple][data-gw-picker]   searchable picker: filter box, click-to-toggle options, removable chips
 *                                       (ATT&CK technique / mitigation fields; the attribute value is the placeholder)
 *  - [data-gw-copy="text"]              copies the text; [data-gw-copy-target="#id"] copies that element's text
 *  - [data-gw-show-tab="tab-id"]        link to #anchor inside a tab: opens the tab, then scrolls to the anchor
 *  - [data-gw-fill="#input"]            radio: copies its value into the input (block dialog targets)
 *  - [data-gw-expiry="hours"]           sets [data-gw-target] datetime-local to now + hours (0 = clear / permanent)
 *  - [data-gw-scan]                     form / link starting an intel investigation: shows the #gw-scan sweep overlay
 */
(function () {
    'use strict';

    function onReady(fn) {
        if (document.readyState !== 'loading') { fn(); } else { document.addEventListener('DOMContentLoaded', fn); }
    }

    // ---- Storage (may be unavailable: private mode, blocked site data) ------
    function store(key, value) {
        try {
            if (value === undefined) { return window.localStorage.getItem(key); }
            if (value === null) { window.localStorage.removeItem(key); } else { window.localStorage.setItem(key, value); }
        } catch (e) { /* ignore */ }
        return null;
    }

    // ---- Sidebar ---------------------------------------------------------
    function initSidebar() {
        var body = document.body;
        var root = document.documentElement;
        document.querySelectorAll('[data-gw-toggle="sidebar"]').forEach(function (el) {
            el.addEventListener('click', function () { body.classList.toggle('gw-sidebar-open'); });
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && body.classList.contains('gw-sidebar-open')) { body.classList.remove('gw-sidebar-open'); }
        });

        var collapse = document.querySelector('[data-gw-collapse="sidebar"]');
        var sync = function () {
            if (!collapse) { return; }
            var collapsed = root.classList.contains('gw-sidebar-collapsed');
            collapse.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            collapse.setAttribute('title', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
            var label = collapse.querySelector('.gw-nav__label');
            if (label) { label.textContent = collapsed ? 'Expand' : 'Collapse'; }
        };
        if (collapse) {
            collapse.addEventListener('click', function () {
                var collapsed = root.classList.toggle('gw-sidebar-collapsed');
                store('gw.sidebar', collapsed ? 'collapsed' : null);
                sync();
            });
        }
        sync();
    }

    // ---- Global search shortcut ("/") --------------------------------------
    function initSearchShortcut() {
        var input = document.getElementById('gw-global-search');
        if (!input) { return; }
        document.addEventListener('keydown', function (e) {
            if (e.key !== '/' || e.ctrlKey || e.metaKey || e.altKey) { return; }
            var t = e.target;
            if (t && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName))) { return; }
            e.preventDefault();
            input.focus();
            input.select();
        });
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { input.blur(); }
        });
    }

    // ---- UTC clock ---------------------------------------------------------
    function initClock() {
        var nodes = document.querySelectorAll('[data-gw-clock]');
        if (!nodes.length) { return; }
        var pad = function (n) { return (n < 10 ? '0' : '') + n; };
        var tick = function () {
            var d = new Date();
            var text = pad(d.getUTCHours()) + ':' + pad(d.getUTCMinutes());
            nodes.forEach(function (el) { el.textContent = text; el.setAttribute('datetime', d.toISOString()); });
        };
        tick();
        setInterval(tick, 15000);
    }

    // ---- Tooltips ------------------------------------------------------------
    function initTooltips() {
        if (!window.bootstrap || !window.bootstrap.Tooltip) { return; }
        document.querySelectorAll('.gw-heatmap__cell[title], .gw-icon-btn[title], .gw-badge[title], [data-bs-toggle="tooltip"]').forEach(function (el) {
            window.bootstrap.Tooltip.getOrCreateInstance(el, { container: 'body', delay: { show: 250, hide: 0 } });
        });
    }

    // ---- Styled confirm dialog for data-confirm (overrides yii.confirm) -------
    function initConfirm() {
        if (!window.yii || !window.bootstrap || !window.bootstrap.Modal) { return; }
        var modalEl = null, modal = null, onOk = null, onCancel = null, confirmed = false;
        var build = function () {
            modalEl = document.createElement('div');
            modalEl.className = 'modal fade gw-confirm';
            modalEl.tabIndex = -1;
            modalEl.setAttribute('aria-hidden', 'true');
            modalEl.setAttribute('aria-labelledby', 'gw-confirm-title');
            modalEl.innerHTML =
                '<div class="modal-dialog modal-dialog-centered"><div class="modal-content">' +
                '<div class="modal-header"><h2 class="modal-title" id="gw-confirm-title">' +
                '<span class="gw-confirm__icon" aria-hidden="true"><i class="bi bi-exclamation-triangle"></i></span> Please confirm</h2>' +
                '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cancel"></button></div>' +
                '<div class="modal-body"><p class="mb-0 gw-confirm__message"></p></div>' +
                '<div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>' +
                '<button type="button" class="btn btn-primary gw-confirm__ok">Confirm</button></div>' +
                '</div></div>';
            document.body.appendChild(modalEl);
            modal = window.bootstrap.Modal.getOrCreateInstance(modalEl);
            modalEl.querySelector('.gw-confirm__ok').addEventListener('click', function () {
                confirmed = true;
                modal.hide();
            });
            modalEl.addEventListener('hidden.bs.modal', function () {
                var cb = confirmed ? onOk : onCancel;
                onOk = onCancel = null;
                confirmed = false;
                if (cb) { cb(); }
            });
        };
        window.yii.confirm = function (message, ok, cancel) {
            if (!modalEl) { build(); }
            var danger = /delete|cannot be undone/i.test(message || '');
            modalEl.classList.toggle('gw-confirm--danger', danger);
            modalEl.querySelector('.gw-confirm__message').textContent = message;
            var okBtn = modalEl.querySelector('.gw-confirm__ok');
            okBtn.className = 'btn gw-confirm__ok ' + (danger ? 'btn-danger' : 'btn-primary');
            okBtn.textContent = danger ? 'Delete' : 'Confirm';
            onOk = ok || null;
            onCancel = cancel || null;
            confirmed = false;
            modal.show();
            modalEl.addEventListener('shown.bs.modal', function () { okBtn.focus(); }, { once: true });
        };
    }

    // ---- Grids: flag tables that scroll sideways (adds a shadow to the pinned actions column) ----
    function initGrids() {
        var bodies = document.querySelectorAll('.gw-grid__body');
        if (!bodies.length) { return; }
        var update = function () {
            bodies.forEach(function (el) { el.classList.toggle('is-scrollable', el.scrollWidth > el.clientWidth + 1); });
        };
        update();
        window.addEventListener('resize', update);
    }

    // ---- Conditional blocks ------------------------------------------------
    function initDepends() {
        document.querySelectorAll('[data-gw-depends]').forEach(function (block) {
            var source = document.querySelector(block.getAttribute('data-gw-depends'));
            if (!source) { return; }
            var expected = (block.getAttribute('data-gw-value') || '').split(',');
            var update = function () {
                var value = source.type === 'checkbox' ? (source.checked ? '1' : '0') : source.value;
                var show = expected.indexOf(value) !== -1;
                block.hidden = !show;
                block.classList.toggle('is-hidden', !show);
            };
            source.addEventListener('change', update);
            update();
        });
    }

    // ---- Device checks: show only those that apply to the device type / ownership ----
    function initDeviceChecks() {
        var blocks = document.querySelectorAll('[data-gw-check-types]');
        var type = document.getElementById('device-device_type');
        if (!blocks.length || !type) { return; }
        var ownership = function () {
            var checked = document.querySelector('input[name="Device[ownership]"]:checked');
            return checked ? checked.value : '';
        };
        var update = function () {
            blocks.forEach(function (block) {
                var types = block.getAttribute('data-gw-check-types');
                var owner = block.getAttribute('data-gw-check-ownership');
                var show = (!types || types.split(',').indexOf(type.value) !== -1) && (owner === 'all' || owner === ownership());
                block.hidden = !show;
            });
        };
        type.addEventListener('change', update);
        document.querySelectorAll('input[name="Device[ownership]"]').forEach(function (r) { r.addEventListener('change', update); });
        update();
    }

    // ---- Cloud checks: show only those that apply to the asset's resource type ----
    function initCloudChecks() {
        var blocks = document.querySelectorAll('[data-gw-cloud-types]');
        var type = document.getElementById('cloudasset-resource_type');
        if (!blocks.length || !type) { return; }
        var update = function () {
            blocks.forEach(function (block) {
                var types = block.getAttribute('data-gw-cloud-types');
                block.hidden = !!types && types.split(',').indexOf(type.value) === -1;
            });
        };
        type.addEventListener('change', update);
        update();
    }

    // ---- Risk score preview ----------------------------------------------------
    function initScorePreview() {
        document.querySelectorAll('[data-gw-score]').forEach(function (box) {
            var l = document.querySelector(box.getAttribute('data-likelihood'));
            var i = document.querySelector(box.getAttribute('data-impact'));
            var out = box.querySelector('.gw-score-preview__value');
            var bands = JSON.parse(box.getAttribute('data-bands') || '[]');
            if (!l || !i || !out) { return; }
            var update = function () {
                var score = parseInt(l.value, 10) * parseInt(i.value, 10);
                out.className = out.className.replace(/\bgw-badge--\S+/g, '').trim();
                if (!score) {
                    out.textContent = '—';
                    out.classList.add('gw-badge--neutral');
                    return;
                }
                var band = bands.find(function (b) { return score <= b.max; }) || bands[bands.length - 1];
                out.textContent = score + ' · ' + band.label;
                out.classList.add('gw-badge--' + band.key);
            };
            l.addEventListener('change', update);
            i.addEventListener('change', update);
            update();
        });
    }

    // ---- Countdown ---------------------------------------------------------------
    function formatDuration(seconds) {
        var s = Math.abs(seconds);
        var d = Math.floor(s / 86400), h = Math.floor((s % 86400) / 3600), m = Math.floor((s % 3600) / 60);
        if (d > 0) { return d + 'd ' + h + 'h'; }
        if (h > 0) { return h + 'h ' + m + 'm'; }
        return m + 'm';
    }

    function initCountdowns() {
        var nodes = document.querySelectorAll('[data-gw-countdown]');
        if (!nodes.length) { return; }
        var tick = function () {
            nodes.forEach(function (el) {
                var deadline = new Date(el.getAttribute('data-gw-countdown')).getTime();
                var diff = Math.round((deadline - Date.now()) / 1000);
                var overdue = diff <= 0;
                el.textContent = formatDuration(diff) + (overdue ? ' overdue' : ' remaining');
                el.classList.toggle('gw-countdown--overdue', overdue);
                el.classList.toggle('gw-countdown--pending', !overdue);
                el.classList.toggle('gw-countdown--urgent', !overdue && diff < 12 * 3600);
                var box = el.closest('.gw-countdown-box');
                if (box) {
                    box.classList.toggle('gw-countdown-box--overdue', overdue);
                    box.classList.toggle('gw-countdown-box--pending', !overdue);
                    box.classList.toggle('gw-countdown-box--urgent', !overdue && diff < 12 * 3600);
                }
            });
        };
        tick();
        setInterval(tick, 30000);
    }

    // ---- Searchable multi-select picker -------------------------------------------
    function initPickers() {
        document.querySelectorAll('select[multiple][data-gw-picker]').forEach(function (select) {
            var wrap = document.createElement('div');
            wrap.className = 'gw-picker';
            var search = document.createElement('input');
            search.type = 'search';
            search.className = 'form-control form-control-sm gw-picker__search';
            search.placeholder = select.getAttribute('data-gw-picker') || 'Filter…';
            search.setAttribute('aria-label', search.placeholder);
            search.setAttribute('autocomplete', 'off');
            if (select.id) { search.setAttribute('aria-controls', select.id); }
            var chips = document.createElement('div');
            chips.className = 'gw-picker__chips';
            chips.setAttribute('aria-live', 'polite');
            select.parentNode.insertBefore(wrap, select);
            wrap.appendChild(search);
            wrap.appendChild(select);
            wrap.appendChild(chips);

            var options = Array.prototype.slice.call(select.options);
            var render = function () {
                chips.textContent = '';
                var selected = options.filter(function (o) { return o.selected; });
                if (!selected.length) {
                    var none = document.createElement('span');
                    none.className = 'gw-picker__none';
                    none.textContent = 'Nothing selected';
                    chips.appendChild(none);
                    return;
                }
                selected.forEach(function (o) {
                    var parts = o.text.split(' · ');
                    var chip = document.createElement('button');
                    chip.type = 'button';
                    chip.className = 'gw-picker__chip';
                    chip.title = 'Remove ' + o.text;
                    chip.setAttribute('aria-label', 'Remove ' + o.text);
                    var id = document.createElement('span');
                    id.className = 'gw-picker__chip-id';
                    id.textContent = parts[0];
                    chip.appendChild(id);
                    if (parts.length > 1) {
                        var name = document.createElement('span');
                        name.className = 'gw-picker__chip-name';
                        name.textContent = parts.slice(1).join(' · ');
                        chip.appendChild(name);
                    }
                    var x = document.createElement('i');
                    x.className = 'bi bi-x';
                    x.setAttribute('aria-hidden', 'true');
                    chip.appendChild(x);
                    chip.addEventListener('click', function () {
                        o.selected = false;
                        select.dispatchEvent(new Event('change', { bubbles: true }));
                    });
                    chips.appendChild(chip);
                });
            };
            var filter = function () {
                var terms = search.value.toLowerCase().split(/\s+/).filter(Boolean);
                options.forEach(function (o) {
                    var text = o.text.toLowerCase();
                    o.hidden = !terms.every(function (t) { return text.indexOf(t) !== -1; });
                });
            };
            // A plain click toggles one option (no Ctrl / Cmd needed); modifier clicks keep the native behaviour
            select.addEventListener('mousedown', function (e) {
                var o = e.target;
                if (o.tagName !== 'OPTION' || e.shiftKey || e.ctrlKey || e.metaKey) { return; }
                e.preventDefault();
                var top = select.scrollTop;
                o.selected = !o.selected;
                select.focus({ preventScroll: true });
                select.scrollTop = top;
                select.dispatchEvent(new Event('change', { bubbles: true }));
            });
            select.addEventListener('change', render);
            search.addEventListener('input', filter);
            search.addEventListener('keydown', function (e) {
                if (e.key !== 'Enter') { return; }
                e.preventDefault(); // never submit the form from the filter box
                var visible = options.filter(function (o) { return !o.hidden; });
                if (visible.length === 1) {
                    visible[0].selected = true;
                    search.value = '';
                    filter();
                    select.dispatchEvent(new Event('change', { bubbles: true }));
                }
            });
            render();
        });
    }

    // ---- Tabs: open by URL hash ------------------------------------------------------
    function initTabs() {
        var hash = window.location.hash;
        if (hash && window.bootstrap) {
            var trigger = document.querySelector('[data-bs-toggle="tab"][href="' + hash + '"], [data-bs-toggle="tab"][data-bs-target="' + hash + '"]');
            if (trigger) { window.bootstrap.Tab.getOrCreateInstance(trigger).show(); }
        }
        document.querySelectorAll('[data-bs-toggle="tab"]').forEach(function (el) {
            el.addEventListener('shown.bs.tab', function (e) {
                var target = e.target.getAttribute('href') || e.target.getAttribute('data-bs-target');
                if (target && history.replaceState) { history.replaceState(null, '', target); }
            });
        });
    }

    // ---- Copy to clipboard ---------------------------------------------------------
    function copyText(text) {
        if (navigator.clipboard && window.isSecureContext) { return navigator.clipboard.writeText(text); }
        return new Promise(function (resolve, reject) {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.setAttribute('readonly', '');
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            try { document.execCommand('copy') ? resolve() : reject(); } catch (e) { reject(e); }
            document.body.removeChild(ta);
        });
    }

    function initCopy() {
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-gw-copy], [data-gw-copy-target]');
            if (!btn) { return; }
            var text = btn.getAttribute('data-gw-copy');
            if (text === null) {
                var target = document.querySelector(btn.getAttribute('data-gw-copy-target'));
                text = target ? target.textContent : '';
            }
            copyText(text).then(function () {
                btn.classList.add('is-copied');
                var icon = btn.querySelector('.bi');
                var old = icon ? icon.className : null;
                if (icon) { icon.className = 'bi bi-check2'; }
                setTimeout(function () { btn.classList.remove('is-copied'); if (icon) { icon.className = old; } }, 1400);
            }, function () { /* clipboard blocked: nothing to do */ });
        });
    }

    // ---- Links into a tab (source sweep -> source panel) ------------------------------
    function initShowTab() {
        document.addEventListener('click', function (e) {
            var link = e.target.closest('[data-gw-show-tab]');
            if (!link || !window.bootstrap) { return; }
            var trigger = document.querySelector('[data-bs-toggle="tab"][href="#' + link.getAttribute('data-gw-show-tab') + '"]');
            var anchor = document.querySelector(link.getAttribute('href'));
            if (!trigger || !anchor) { return; }
            e.preventDefault();
            var go = function () {
                anchor.scrollIntoView({ behavior: 'smooth', block: 'start' });
                if (history.replaceState) { history.replaceState(null, '', link.getAttribute('href')); }
                anchor.classList.add('is-flash');
                setTimeout(function () { anchor.classList.remove('is-flash'); }, 1600);
            };
            if (trigger.classList.contains('active')) { go(); return; }
            trigger.addEventListener('shown.bs.tab', go, { once: true });
            window.bootstrap.Tab.getOrCreateInstance(trigger).show();
        });
    }

    // ---- Block dialog helpers -----------------------------------------------------------
    function initBlockForm() {
        document.querySelectorAll('[data-gw-fill]').forEach(function (radio) {
            radio.addEventListener('change', function () {
                var input = document.querySelector(radio.getAttribute('data-gw-fill'));
                if (input && radio.checked) { input.value = radio.value; }
            });
        });
        var pad = function (n) { return (n < 10 ? '0' : '') + n; };
        document.querySelectorAll('[data-gw-expiry]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var input = document.querySelector(btn.getAttribute('data-gw-target'));
                if (!input) { return; }
                var hours = parseInt(btn.getAttribute('data-gw-expiry'), 10);
                if (!hours) {
                    input.value = '';
                } else {
                    var d = new Date(Date.now() + hours * 3600000);
                    input.value = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
                }
                btn.parentNode.querySelectorAll('[data-gw-expiry]').forEach(function (b) { b.classList.toggle('is-active', b === btn); });
                input.dispatchEvent(new Event('change', { bubbles: true }));
            });
        });
    }

    // ---- Investigation sweep overlay ------------------------------------------------------
    function initScan() {
        var overlay = document.getElementById('gw-scan');
        if (!overlay) { return; }
        var timer = null;
        var sources = [];
        try { sources = JSON.parse(overlay.getAttribute('data-sources') || '[]'); } catch (e) { sources = []; }
        var line = overlay.querySelector('[data-gw-scan-source]');
        var target = overlay.querySelector('[data-gw-scan-target]');
        var show = function (value) {
            if (value && target) { target.textContent = value; }
            overlay.hidden = false;
            var i = 0;
            timer = setInterval(function () {
                if (line && sources.length) { line.textContent = 'querying ' + sources[i % sources.length] + '…'; }
                i++;
            }, 220);
        };
        var hide = function () { overlay.hidden = true; if (timer) { clearInterval(timer); timer = null; } };
        document.querySelectorAll('form[data-gw-scan]').forEach(function (form) {
            form.addEventListener('submit', function () {
                var input = form.querySelector('input[name="q"]');
                show(input ? input.value.trim() : '');
            });
        });
        document.querySelectorAll('a[data-gw-scan]').forEach(function (a) {
            a.addEventListener('click', function (e) {
                if (e.ctrlKey || e.metaKey || e.shiftKey || e.button !== 0) { return; }
                show('');
            });
        });
        // Back / forward cache restores the page with the overlay still showing
        window.addEventListener('pageshow', hide);
    }

    onReady(function () {
        initSidebar();
        initSearchShortcut();
        initClock();
        initTooltips();
        initConfirm();
        initGrids();
        initDepends();
        initDeviceChecks();
        initCloudChecks();
        initScorePreview();
        initCountdowns();
        initTabs();
        initPickers();
        initCopy();
        initShowTab();
        initBlockForm();
        initScan();
    });
})();
