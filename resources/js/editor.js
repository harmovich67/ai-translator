/**
 * Live Translate - Translate mode.
 *
 * Toggle "Live translate" in the dashboard header (or the floating "Translate" button on the site), hover any text
 * (highlighted), click it: a popup shows the text in every language and saves it where it is stored. The popup lives
 * in a shadow root so no site or dashboard stylesheet can change how it looks.
 *
 * The dashboard button also decides whether the site shows its floating button: closed = hidden, opened = shown
 * (shared by every tab of the browser through localStorage).
 */
(function () {
    'use strict';

    const config = window.LT_EDITOR;
    const runtime = window.LTRuntime;
    if (!config || !runtime) {
        return;
    }

    const strings = config.strings || {};
    const STORAGE_KEY = 'lt-active';
    // Shared by every tab of the browser: the dashboard button decides whether the site shows its Translate button.
    const BUTTON_KEY = 'lt-button';
    const ATTRIBUTE_TARGETS = [
        {selector: 'input[placeholder],textarea[placeholder]', attribute: 'placeholder'},
        {selector: 'input[type="submit"],input[type="button"],input[type="reset"]', attribute: 'value'},
        {selector: 'img[alt]', attribute: 'alt'},
        // title/aria-label: lt-runtime.js already applies dictionary translations to these (tooltips, icon-only
        // buttons), but nothing could click them to create that translation in the first place until now.
        {selector: '[title]', attribute: 'title'},
        {selector: '[aria-label]', attribute: 'aria-label'},
        // The "delete?" confirmation text of admin list pages (data-confirm on .delete-form): not visible until the
        // click, but still text a visitor reads, and worth catching so "Translate page" does not skip it.
        {selector: '[data-confirm]', attribute: 'data-confirm'}
    ];

    let active = false;
    let hoverTarget = null;
    let popupOpen = false;
    let frame = 0;
    let host, root, highlight, toastTimer, topButton;

    /* ------------------------------------------------------------------ helpers */

    function h(tag, attributes) {
        const element = document.createElement(tag);
        Object.keys(attributes || {}).forEach(function (key) {
            if (key === 'text') {
                element.textContent = attributes[key];
            } else if (key === 'class') {
                element.className = attributes[key];
            } else if (attributes[key] !== null && attributes[key] !== undefined) {
                element.setAttribute(key, attributes[key]);
            }
        });
        for (let i = 2; i < arguments.length; i++) {
            if (arguments[i]) {
                element.appendChild(typeof arguments[i] === 'string' ? document.createTextNode(arguments[i]) : arguments[i]);
            }
        }
        return element;
    }

    function hasLetters(text) {
        return /\p{L}/u.test(text);
    }

    /** Language a text without a known source is written in: the page language, unless the script says otherwise. */
    function guessLanguage(text) {
        const rtlText = /[֐-ࣿיִ-﷿ﹰ-﻿]/.test(text);
        const compatible = config.languages.filter(function (language) { return !!language.rtl === rtlText; });
        if (!compatible.length) {
            return config.lang;
        }
        const preferred = [config.lang, config.defaultLang].filter(function (code) {
            return compatible.some(function (language) { return language.code === code; });
        });
        return preferred[0] || compatible[0].code;
    }

    function pageDirection() {
        return document.documentElement.getAttribute('dir') || (config.rtl ? 'rtl' : 'ltr');
    }

    function insideUi(event) {
        const path = event.composedPath ? event.composedPath() : [];
        // The dashboard button (and anything marked data-lt-skip) keeps working as a button while the mode is on.
        return path.indexOf(host) !== -1 || (event.target && event.target.closest && !!event.target.closest('[data-lt-skip]'));
    }

    /* ------------------------------------------------------------------ finding the text under the pointer */

    function caretNode(x, y) {
        if (document.caretPositionFromPoint) {
            const position = document.caretPositionFromPoint(x, y);
            return position ? position.offsetNode : null;
        }
        if (document.caretRangeFromPoint) {
            const range = document.caretRangeFromPoint(x, y);
            return range ? range.startContainer : null;
        }
        return null;
    }

    function pointIn(rect, x, y) {
        return x >= rect.left - 2 && x <= rect.right + 2 && y >= rect.top - 2 && y <= rect.bottom + 2;
    }

    /** @return {{kind:string,text:string,rect:DOMRect,node?:Node,element?:Element,attribute?:string}|null} */
    function locate(x, y) {
        const element = document.elementFromPoint(x, y);
        if (!element || runtime.isSkipped(element)) {
            return null;
        }

        const node = caretNode(x, y);
        if (node && node.nodeType === 3 && hasLetters(node.nodeValue) && !runtime.isSkipped(node.parentElement)) {
            const range = document.createRange();
            range.selectNodeContents(node);
            const rects = Array.prototype.slice.call(range.getClientRects());
            if (rects.some(function (rect) { return pointIn(rect, x, y); })) {
                return {kind: 'text', text: node.nodeValue.trim(), rect: range.getBoundingClientRect(), node: node};
            }
        }

        for (let i = 0; i < ATTRIBUTE_TARGETS.length; i++) {
            const candidate = ATTRIBUTE_TARGETS[i];
            const owner = element.closest(candidate.selector);
            if (owner) {
                const value = candidate.attribute === 'value' ? owner.value : owner.getAttribute(candidate.attribute);
                if (value && hasLetters(value)) {
                    return {kind: 'attribute', text: value.trim(), rect: owner.getBoundingClientRect(), element: owner, attribute: candidate.attribute};
                }
            }
        }
        return null;
    }

    /* ------------------------------------------------------------------ UI shell (shadow root) */

    const CSS = `
        :host { all: initial; }
        * { box-sizing: border-box; }
        .hl { position: fixed; z-index: 1; pointer-events: none; border: 2px solid #2563eb; background: rgba(37,99,235,.12); border-radius: 4px; display: none; }
        .hl span { position: absolute; top: -24px; inset-inline-start: -2px; background: #2563eb; color: #fff; font: 600 11px/1 system-ui, Tahoma, sans-serif; padding: 5px 8px; border-radius: 4px 4px 4px 0; white-space: nowrap; }
        .fab { position: fixed; z-index: 2; bottom: 18px; inset-inline-start: 18px; pointer-events: auto; border: 0; border-radius: 999px; padding: 11px 16px; font: 600 13px/1 system-ui, Tahoma, sans-serif; background: #1f2937; color: #fff; cursor: pointer; box-shadow: 0 6px 20px rgba(0,0,0,.25); }
        .fab.on { background: #2563eb; }
        .toast { position: fixed; z-index: 4; bottom: 28px; left: 50%; transform: translateX(-50%); background: #111827; color: #fff; font: 500 13px/1.5 system-ui, Tahoma, sans-serif; padding: 10px 16px; border-radius: 10px; box-shadow: 0 8px 24px rgba(0,0,0,.3); max-width: min(92vw, 520px); pointer-events: none; }
        .backdrop { position: fixed; inset: 0; z-index: 3; background: rgba(15,23,42,.45); display: flex; align-items: center; justify-content: center; padding: 16px; pointer-events: auto; }
        .dialog { width: min(560px, 100%); max-height: 92vh; display: flex; flex-direction: column; background: #fff; color: #111827; border-radius: 16px; box-shadow: 0 24px 64px rgba(0,0,0,.35); font: 400 14px/1.5 system-ui, Tahoma, sans-serif; overflow: hidden; }
        header { display: flex; align-items: center; justify-content: space-between; padding: 16px 20px; border-bottom: 1px solid #e5e7eb; }
        header h2 { margin: 0; font-size: 16px; font-weight: 700; }
        .x { border: 0; background: transparent; font-size: 22px; line-height: 1; cursor: pointer; color: #6b7280; padding: 2px 6px; border-radius: 6px; }
        .x:hover { background: #f3f4f6; color: #111827; }
        .body { padding: 16px 20px; overflow: auto; display: grid; gap: 14px; }
        .label { display: block; font-size: 12px; font-weight: 600; color: #6b7280; margin-bottom: 4px; }
        .source { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 10px; padding: 10px 12px; max-height: 96px; overflow: auto; white-space: pre-wrap; word-break: break-word; }
        .field label { display: flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 600; margin-bottom: 4px; }
        textarea { width: 100%; min-height: 40px; resize: vertical; padding: 9px 11px; border: 1px solid #d1d5db; border-radius: 10px; font: 400 14px/1.5 system-ui, Tahoma, sans-serif; color: inherit; background: #fff; }
        textarea:focus { outline: 2px solid #2563eb; outline-offset: 0; border-color: #2563eb; }
        .where { border: 1px solid #e5e7eb; border-radius: 10px; padding: 10px 12px; display: grid; gap: 8px; }
        .where label.row { display: flex; gap: 8px; align-items: flex-start; font-size: 13px; cursor: pointer; }
        .where .type { background: #eff6ff; color: #1d4ed8; border-radius: 999px; padding: 1px 8px; font-size: 11px; font-weight: 600; white-space: nowrap; }
        .where a { color: #2563eb; font-size: 12px; text-decoration: none; margin-inline-start: auto; white-space: nowrap; }
        .note { font-size: 13px; color: #92400e; background: #fffbeb; border: 1px solid #fde68a; border-radius: 10px; padding: 9px 12px; }
        .error { font-size: 13px; color: #b91c1c; background: #fef2f2; border: 1px solid #fecaca; border-radius: 10px; padding: 9px 12px; }
        footer { display: flex; align-items: center; gap: 10px; padding: 14px 20px; border-top: 1px solid #e5e7eb; background: #f9fafb; }
        footer .hint { margin-inline-end: auto; font-size: 12px; color: #6b7280; }
        button.btn { border: 1px solid #d1d5db; background: #fff; color: #111827; border-radius: 10px; padding: 9px 16px; font: 600 13px/1 system-ui, Tahoma, sans-serif; cursor: pointer; }
        button.btn.primary { background: #2563eb; border-color: #2563eb; color: #fff; }
        button.btn:disabled { opacity: .6; cursor: default; }
        .head { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 4px; }
        .head .label, .field-head label { margin: 0; }
        .field-head { display: flex; align-items: center; gap: 6px; margin-bottom: 4px; }
        button.ai { border: 1px solid #c7d2fe; background: #eef2ff; color: #4338ca; border-radius: 999px; padding: 4px 11px; font: 600 12px/1.4 system-ui, Tahoma, sans-serif; cursor: pointer; white-space: nowrap; }
        button.ai:hover { background: #e0e7ff; }
        button.ai.mini { padding: 1px 7px; margin-inline-start: auto; }
        button.ai:disabled { opacity: .6; cursor: default; }
        .loading { padding: 28px 20px; text-align: center; color: #6b7280; }
        .fab.ai2 { bottom: 70px; }
        .page-dialog { width: min(720px, 100%); }
        .page-toolbar { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
        .page-toolbar a { font-size: 12px; color: #2563eb; text-decoration: none; cursor: pointer; }
        .page-status { font-size: 12px; color: #6b7280; }
        .rows { display: grid; gap: 10px; }
        .row-card { border: 1px solid #e5e7eb; border-radius: 10px; padding: 10px 12px; display: grid; gap: 8px; }
        .row-card.amb { border-color: #fde68a; background: #fffbeb; }
        .row-card.saved { opacity: .55; }
        .row-head { display: flex; align-items: flex-start; gap: 8px; }
        .row-head .src { flex: 1; font-size: 13px; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .row-known { font-size: 12px; color: #6b7280; }
        .row-fields { display: grid; gap: 6px; }
        .row-fields .rf { display: grid; gap: 3px; }
        .row-fields label { font-size: 11px; font-weight: 600; color: #6b7280; }
        .row-fields textarea { min-height: 34px; }
        .row-note { font-size: 12px; color: #92400e; }
        .row-msg { font-size: 12px; color: #b91c1c; }
        .row-extra { font-size: 12px; display: flex; align-items: center; gap: 6px; color: #374151; cursor: pointer; }
        .banner { font-size: 13px; padding: 8px 12px; border-radius: 8px; }
        .banner.warn { color: #92400e; background: #fffbeb; border: 1px solid #fde68a; }
        @media (prefers-color-scheme: dark) {
            .dialog { background: #111827; color: #f3f4f6; }
            header, footer { border-color: #1f2937; }
            footer { background: #0b1220; }
            .source { background: #0b1220; border-color: #1f2937; }
            textarea { background: #0b1220; border-color: #374151; }
            .where { border-color: #1f2937; }
            .x:hover { background: #1f2937; color: #fff; }
            button.btn { background: #1f2937; border-color: #374151; color: #f3f4f6; }
            button.btn.primary { background: #2563eb; border-color: #2563eb; }
            button.ai { background: #1e1b4b; border-color: #3730a3; color: #c7d2fe; }
            button.ai:hover { background: #312e81; }
            .row-card { border-color: #1f2937; }
            .row-card.amb { border-color: #92400e; background: #1f2937; }
            .banner.warn { background: #1f2937; border-color: #92400e; }
        }
    `;

    function buildShell() {
        host = h('div', {id: 'lt-host', 'data-lt-skip': ''});
        // The site's AI assistant sits at the end of the page, so the floating button takes the start side.
        // ':host { all: initial }' resets the direction, so it is set here (an inline style beats :host rules).
        host.style.cssText = 'position:fixed;inset:0;z-index:2147483646;pointer-events:none;direction:' + pageDirection() + ';';
        root = host.attachShadow({mode: 'open'});
        highlight = h('div', {class: 'hl'}, h('span', {text: strings.clickToTranslate || 'Translate'}));
        root.appendChild(h('style', {text: CSS}));
        root.appendChild(highlight);
        document.body.appendChild(host);

        mountToggle();
    }

    /**
     * Where the Translate button lives: the button in the dashboard header (printed by the layout), or a floating
     * button (the site, and a dashboard page without the header button).
     */
    function mountToggle() {
        const button = document.getElementById('lt-admin-toggle');
        if (button) {
            topButton = button;
            button.addEventListener('click', toggleFromButton);
            document.head.appendChild(h('style', {text: '#lt-admin-toggle.lt-on{background:#2563eb!important;border-color:#2563eb!important;color:#fff!important}'}));
            button.classList.toggle('lt-on', active);
            mountPageButton(button);
            return;
        }

        const fab = h('button', {class: 'fab', type: 'button', text: strings.toggle || 'Translate'});
        fab.addEventListener('click', toggleFromButton);
        root.appendChild(fab);
        root.fab = fab;
        fab.classList.toggle('on', active);
        applyButtonVisibility();
        mountPageButton(null);
    }

    /**
     * "Translate page" button: an independent action (does not need Translate mode to be on first), only shown when
     * Gemini is on. Next to the dashboard header button when there is one, otherwise a second floating button.
     */
    function mountPageButton(afterElement) {
        if (!config.ai || !config.ai.enabled) {
            return;
        }
        if (afterElement) {
            const button = h('button', {
                type: 'button', 'data-lt-skip': '', title: strings.pageTitle,
                class: afterElement.className, style: 'margin-inline-start:8px'
            });
            button.textContent = '✨ ' + (strings.pageToggle || 'Translate page');
            button.addEventListener('click', function () { openPageDialog(); });
            afterElement.insertAdjacentElement('afterend', button);
            return;
        }
        const fab = h('button', {class: 'fab ai2', type: 'button', title: strings.pageTitle, text: '✨'});
        fab.addEventListener('click', function () { openPageDialog(); });
        root.appendChild(fab);
        root.fabPage = fab;
        applyButtonVisibility();
    }

    /** Site only: the button is hidden ('0') from the dashboard, and shown again when the dashboard button is turned on. */
    function buttonEnabled() {
        try {
            return localStorage.getItem(BUTTON_KEY) !== '0';
        } catch (e) {
            return true;
        }
    }

    function saveButtonEnabled(on) {
        try {
            localStorage.setItem(BUTTON_KEY, on ? '1' : '0');
        } catch (e) { /* storage may be blocked */ }
    }

    function applyButtonVisibility() {
        if (config.isAdmin) {
            return;
        }
        const enabled = buttonEnabled();
        if (root && root.fab) {
            root.fab.style.display = enabled ? '' : 'none';
        }
        if (root && root.fabPage) {
            root.fabPage.style.display = enabled ? '' : 'none';
        }
        if (!enabled) {
            setActive(false);
        }
    }

    /** The dashboard button and the floating button both end up here. */
    function toggleFromButton() {
        const on = !active;
        if (config.isAdmin) {
            saveButtonEnabled(on);
        }
        setActive(on);
    }

    function toast(message, duration) {
        if (toastTimer) {
            clearTimeout(toastTimer);
        }
        const previous = root.querySelector('.toast');
        if (previous) {
            previous.remove();
        }
        const element = h('div', {class: 'toast', role: 'status', text: message});
        root.appendChild(element);
        toastTimer = setTimeout(function () { element.remove(); }, duration || 3200);
    }

    function showHighlight(target) {
        if (!target) {
            highlight.style.display = 'none';
            return;
        }
        const rect = target.rect;
        highlight.style.cssText = 'display:block;left:' + (rect.left - 3) + 'px;top:' + (rect.top - 2) + 'px;width:' + (rect.width + 6) + 'px;height:' + (rect.height + 4) + 'px;';
    }

    /* ------------------------------------------------------------------ Translate mode */

    function onMove(event) {
        if (popupOpen || insideUi(event)) {
            return;
        }
        const x = event.clientX;
        const y = event.clientY;
        if (frame) {
            return;
        }
        frame = requestAnimationFrame(function () {
            frame = 0;
            hoverTarget = locate(x, y);
            showHighlight(hoverTarget);
        });
    }

    function onClick(event) {
        if (popupOpen || insideUi(event) || event.ctrlKey || event.metaKey || event.button !== 0) {
            return;
        }
        const target = locate(event.clientX, event.clientY);
        if (!target) {
            return;
        }
        event.preventDefault();
        event.stopPropagation();
        event.stopImmediatePropagation();
        openPopup(target);
    }

    function swallow(event) {
        // Keep buttons/links from starting their own action while a text is being picked.
        if (!popupOpen && !insideUi(event) && !event.ctrlKey && !event.metaKey && locate(event.clientX, event.clientY)) {
            event.preventDefault();
            event.stopPropagation();
        }
    }

    function onKey(event) {
        if (event.key === 'Escape' && !popupOpen) {
            setActive(false);
        }
    }

    function setActive(on) {
        if (on === active) {
            return;
        }
        active = on;
        document.documentElement.classList.toggle('lt-active', on);
        try {
            sessionStorage.setItem(STORAGE_KEY, on ? '1' : '0');
        } catch (e) { /* storage may be blocked */ }

        const method = on ? 'addEventListener' : 'removeEventListener';
        document[method]('mousemove', onMove, {capture: true, passive: true});
        document[method]('click', onClick, true);
        document[method]('mousedown', swallow, true);
        document[method]('mouseup', swallow, true);
        document[method]('keydown', onKey, true);
        window[method]('scroll', hideHighlight, true);

        if (root && root.fab) {
            root.fab.classList.toggle('on', on);
        }
        if (topButton) {
            topButton.classList.toggle('lt-on', on);
        }
        if (!on) {
            hoverTarget = null;
            showHighlight(null);
        }
        toast(on ? strings.toggleOn : strings.toggleOff, on ? 4200 : 2000);
    }

    function hideHighlight() {
        hoverTarget = null;
        showHighlight(null);
    }

    /* ------------------------------------------------------------------ popup */

    function closePopup() {
        const backdrop = root.querySelector('.backdrop');
        if (backdrop) {
            backdrop.remove();
        }
        popupOpen = false;
    }

    function post(action, data) {
        const body = new URLSearchParams();
        body.set('lang', config.lang);
        body.set('path', location.pathname);
        Object.keys(data).forEach(function (key) {
            const value = data[key];
            if (Array.isArray(value)) {
                value.forEach(function (item) { body.append(key + '[]', item); });
            } else if (value && typeof value === 'object') {
                Object.keys(value).forEach(function (code) { body.set(key + '[' + code + ']', value[code]); });
            } else {
                body.set(key, value);
            }
        });
        return fetch(config.endpoint + '/' + action, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'X-CSRF-Token': config.csrf, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json'},
            body: body
        }).then(function (response) { return response.json(); });
    }

    function defaultChecked(candidates) {
        const own = candidates.filter(function (candidate) { return candidate.current; });
        if (own.length) {
            return own;
        }
        // Several unrelated places can share a text (a menu label "Services" and a page called "Services"):
        // only a single, unambiguous place is preselected, otherwise the user chooses.
        return candidates.length === 1 ? candidates : [];
    }

    function openPopup(target) {
        popupOpen = true;
        hideHighlight();

        const dialog = h('div', {class: 'dialog', role: 'dialog', 'aria-modal': 'true', dir: pageDirection()},
            h('header', {}, h('h2', {text: strings.title}), h('button', {class: 'x', type: 'button', 'aria-label': strings.cancel, text: '×'})),
            h('div', {class: 'loading', text: strings.loading})
        );
        const backdrop = h('div', {class: 'backdrop'}, dialog);
        backdrop.addEventListener('mousedown', function (event) {
            if (event.target === backdrop) {
                closePopup();
            }
        });
        dialog.querySelector('.x').addEventListener('click', closePopup);
        root.appendChild(backdrop);

        post('resolve', {text: target.text})
            .then(function (response) {
                if (!response.success) {
                    throw new Error('resolve');
                }
                renderForm(dialog, target, response.data);
            })
            .catch(function () {
                closePopup();
                toast(strings.error, 4000);
            });
    }

    function renderForm(dialog, target, data) {
        const candidates = data.candidates || [];
        const known = candidates[0] ? candidates[0].values : (data.dictionary ? data.dictionary.values : null);
        const preselected = defaultChecked(candidates).map(function (candidate) { return candidate.ref; });
        const fields = {};
        const aiOn = !!(config.ai && config.ai.enabled);
        const aiButtons = [];
        let aiAll = null;

        const fieldsBox = h('div', {class: 'body-fields', style: 'display:grid;gap:12px'});
        config.languages.forEach(function (language) {
            const input = h('textarea', {rows: 1, dir: language.rtl ? 'rtl' : 'ltr', lang: language.code, 'data-lang': language.code, placeholder: strings.empty || ''});
            input.value = known && known[language.code] !== undefined ? known[language.code] : (language.code === guessLanguage(target.text) ? target.text : '');
            fields[language.code] = input;
            let own = null;
            if (aiOn) {
                own = h('button', {class: 'ai mini', type: 'button', title: strings.aiField, 'aria-label': strings.aiField, text: '✨'});
                own.addEventListener('click', function () { aiTranslate([language.code]); });
                aiButtons.push(own);
            }
            fieldsBox.appendChild(h('div', {class: 'field'},
                h('div', {class: 'field-head'},
                    h('label', {}, language.flag ? h('span', {text: language.flag}) : null, language.name + ' (' + language.code + ')'),
                    own),
                input));
        });

        const checks = {};
        let where = null;
        let dictionaryBox = null;
        if (candidates.length) {
            const all = candidates.length > 1 ? h('a', {href: '#', text: strings.selectAll}) : null;
            if (all) {
                all.addEventListener('click', function (event) {
                    event.preventDefault();
                    Object.keys(checks).forEach(function (ref) { checks[ref].checked = true; });
                });
            }
            where = h('div', {class: 'where'}, h('span', {class: 'label', text: strings.where + ' (' + candidates.length + ')'}), all);
            candidates.forEach(function (candidate) {
                const checkbox = h('input', {type: 'checkbox'});
                checkbox.checked = preselected.indexOf(candidate.ref) !== -1;
                checks[candidate.ref] = checkbox;
                const link = candidate.edit_url ? h('a', {href: candidate.edit_url, target: '_blank', rel: 'noopener', text: strings.openEditor}) : null;
                where.appendChild(h('label', {class: 'row'}, checkbox,
                    h('span', {class: 'type', text: strings['type_' + candidate.kind] || candidate.kind}),
                    h('span', {text: candidate.label}), link));
            });
            dictionaryBox = h('input', {type: 'checkbox'});
            where.appendChild(h('label', {class: 'row'}, dictionaryBox, h('span', {text: strings.alsoDictionary})));
        }

        if (aiOn) {
            aiAll = h('button', {class: 'ai', type: 'button', text: '✨ ' + strings.aiAll});
            aiAll.addEventListener('click', function () { aiTranslate(null); });
            aiButtons.push(aiAll);
        }

        const error = h('div', {class: 'error', hidden: ''});
        const save = h('button', {class: 'btn primary', type: 'button', text: strings.save});
        const cancel = h('button', {class: 'btn', type: 'button', text: strings.cancel});

        while (dialog.children.length > 1) {
            dialog.removeChild(dialog.lastChild);
        }
        dialog.appendChild(h('div', {class: 'body'},
            h('div', {}, h('span', {class: 'label', text: strings.original}), h('div', {class: 'source', dir: 'auto', text: target.text})),
            h('div', {}, h('div', {class: 'head'}, h('span', {class: 'label', text: strings.languages}), aiAll), fieldsBox),
            where,
            candidates.length ? null : h('div', {class: 'note', text: strings.noSource}),
            error));
        dialog.appendChild(h('footer', {}, h('span', {class: 'hint', text: strings.hint}), cancel, save));

        function showError(message) {
            error.textContent = message;
            error.hidden = false;
        }

        /**
         * Asks Gemini to fill the given languages (null = every empty one) from the first language that has a text.
         * The result only lands in the fields: the administrator reads it and presses Save.
         */
        function aiTranslate(codes) {
            const guessed = guessLanguage(target.text);
            const order = [guessed, config.lang, config.defaultLang].concat(config.languages.map(function (language) { return language.code; }));
            const wanted = codes || config.languages.map(function (language) { return language.code; }).filter(function (code) {
                return fields[code].value.trim() === '';
            });
            if (!wanted.length) {
                showError(strings.aiNothing);
                return;
            }
            const from = order.filter(function (code) {
                return fields[code] && wanted.indexOf(code) === -1 && fields[code].value.trim() !== '';
            })[0];
            if (!from) {
                showError(strings.aiNoSource);
                return;
            }

            error.hidden = true;
            const labels = aiButtons.map(function (button) { return button.textContent; });
            aiButtons.forEach(function (button) { button.disabled = true; });
            if (aiAll) {
                aiAll.textContent = strings.aiWorking;
            }
            post('ai', {text: fields[from].value, from: from, to: wanted})
                .then(function (response) {
                    if (!response.success) {
                        throw new Error((response.data && response.data.message) || strings.error);
                    }
                    Object.keys(response.data.values || {}).forEach(function (code) {
                        if (fields[code]) {
                            fields[code].value = response.data.values[code];
                        }
                    });
                    toast(strings.aiDone, 3600);
                })
                .catch(function (problem) {
                    showError(problem && problem.message && problem.message !== 'Failed to fetch' ? problem.message : strings.error);
                })
                .then(function () {
                    aiButtons.forEach(function (button, i) {
                        button.disabled = false;
                        button.textContent = labels[i];
                    });
                });
        }

        function submit() {
            const values = {};
            Object.keys(fields).forEach(function (code) { values[code] = fields[code].value; });
            const refs = Object.keys(checks).filter(function (ref) { return checks[ref].checked; });
            const dictionary = !candidates.length || (dictionaryBox && dictionaryBox.checked);
            const filled = Object.keys(values).some(function (code) { return values[code].trim() !== ''; });
            if (!filled || (!refs.length && !dictionary)) {
                showError(strings.nothingToSave);
                return;
            }

            error.hidden = true;
            save.disabled = true;
            save.textContent = strings.saving;
            post('save', {text: target.text, values: values, targets: refs, dictionary: dictionary ? '1' : '0'})
                .then(function (response) {
                    if (!response.success) {
                        const failed = ((response.data && response.data.results) || []).filter(function (r) { return !r.ok; });
                        throw new Error(failed.length ? failed[0].message : strings.error);
                    }
                    const result = response.data;
                    if (result.replacement) {
                        runtime.replace(target.text, result.replacement);
                    }
                    if (result.runtime) {
                        runtime.add(result.runtime);
                    }
                    const failed = result.results.filter(function (r) { return !r.ok; });
                    closePopup();
                    toast(failed.length ? strings.saved + ' ' + failed[0].message : strings.saved, failed.length ? 6000 : 2600);
                })
                .catch(function (problem) {
                    save.disabled = false;
                    save.textContent = strings.save;
                    showError(problem && problem.message && problem.message !== 'Failed to fetch' ? problem.message : strings.error);
                });
        }

        save.addEventListener('click', submit);
        cancel.addEventListener('click', closePopup);
        dialog.querySelector('.x').addEventListener('click', closePopup);
        dialog.addEventListener('keydown', function (event) {
            event.stopPropagation();
            if (event.key === 'Escape') {
                closePopup();
            } else if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) {
                event.preventDefault();
                submit();
            }
        });

        const firstEmpty = config.languages.map(function (language) { return fields[language.code]; }).filter(function (input) { return input.value.trim() === ''; })[0];
        (firstEmpty || fields[config.languages[0].code]).focus();
    }

    /* ------------------------------------------------------------------ "Translate the whole page" */

    /**
     * Every visible text of the current page, same rule as locate() (text nodes + the placeholder/value/alt
     * attributes of ATTRIBUTE_TARGETS), deduplicated by runtime.norm() and capped to config.scanLimit.
     * @return {{text:string}[]}
     */
    function scanPage() {
        const limit = config.scanLimit || 60;
        const seen = {};
        const out = [];

        function add(raw) {
            const text = String(raw).trim();
            if (!text || out.length >= limit || !hasLetters(text)) {
                return;
            }
            const key = runtime.norm(text);
            if (!key || seen[key]) {
                return;
            }
            seen[key] = true;
            out.push({text: text});
        }

        const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT, {
            acceptNode: function (node) {
                return node.nodeValue.trim() === '' || runtime.isSkipped(node.parentElement) ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT;
            }
        });
        while (out.length < limit && walker.nextNode()) {
            add(walker.currentNode.nodeValue);
        }

        ATTRIBUTE_TARGETS.forEach(function (target) {
            if (out.length >= limit) {
                return;
            }
            document.querySelectorAll(target.selector).forEach(function (element) {
                if (out.length >= limit || runtime.isSkipped(element)) {
                    return;
                }
                const value = target.attribute === 'value' ? element.value : element.getAttribute(target.attribute);
                if (value) {
                    add(value);
                }
            });
        });

        return out;
    }

    /**
     * One row per scanned text that is missing at least one language (a text already complete in every language has
     * nothing to do and is left out). Mirrors the "known" / defaultChecked() logic of the single popup exactly, so
     * the two flows never disagree about where a text is stored or which language counts as already filled.
     */
    function buildRows(items) {
        const rows = [];
        items.forEach(function (item, index) {
            const candidates = item.candidates || [];
            const known = candidates[0] ? candidates[0].values : (item.dictionary ? item.dictionary.values : null);
            const guessed = guessLanguage(item.text);
            const missing = config.languages
                .filter(function (language) {
                    if (language.code === guessed) {
                        return false; // the visible text itself already is that language
                    }
                    const value = known && known[language.code];
                    return !value || !value.trim();
                })
                .map(function (language) { return language.code; });
            if (!missing.length) {
                return;
            }
            const own = candidates.filter(function (c) { return c.current; });
            const targets = own.length ? own.map(function (c) { return c.ref; }) : (candidates.length === 1 ? [candidates[0].ref] : []);
            rows.push({
                id: 'r' + index,
                text: item.text,
                candidates: candidates,
                known: known,
                guessed: guessed,
                missing: missing,
                targets: targets,
                // Several unrelated places share this exact text: like the single popup, nothing is preselected,
                // the row stays out of "select all" unless the admin opts in with "also save as a site-wide translation".
                ambiguous: !own.length && candidates.length > 1,
                noStorage: candidates.length === 0,
                values: {},
                aiFailed: false
            });
        });
        return rows;
    }

    /**
     * Fills row.values from Gemini, grouped by guessed source language so a page in one language costs one request
     * (chunked server-side). Never rejects: a failure is recorded per row (aiFailed) and in the returned banner
     * message, so the rest of the rows still render and can be filled by hand.
     * @return {Promise<string|null>} banner message, if anything went wrong.
     */
    function translateRows(dialog, rows) {
        const loadingEl = dialog.querySelector('.loading');
        if (loadingEl) {
            loadingEl.textContent = strings.pageTranslating;
        }
        const groups = {};
        rows.forEach(function (row) {
            (groups[row.guessed] = groups[row.guessed] || []).push(row);
        });

        let banner = null;
        let chain = Promise.resolve();
        Object.keys(groups).forEach(function (from) {
            const group = groups[from];
            chain = chain.then(function () {
                const items = {};
                const to = [];
                group.forEach(function (row) {
                    items[row.id] = row.text;
                    row.missing.forEach(function (code) {
                        if (to.indexOf(code) === -1) {
                            to.push(code);
                        }
                    });
                });
                return post('ai-many', {items: items, from: from, to: to})
                    .then(function (response) {
                        if (!response.success) {
                            banner = (response.data && response.data.message) || strings.error;
                            return;
                        }
                        const values = response.data.values || {};
                        group.forEach(function (row) {
                            const got = values[row.id];
                            row.missing.forEach(function (code) {
                                if (got && got[code]) {
                                    row.values[code] = got[code];
                                }
                            });
                            row.aiFailed = row.missing.some(function (code) { return !row.values[code]; });
                        });
                        if (response.data.message) {
                            banner = response.data.message;
                        }
                    })
                    .catch(function () {
                        banner = strings.error;
                    });
            });
        });
        return chain.then(function () { return banner; });
    }

    /** Builds the review list (one card per row) and wires "select all", per-row checkboxes and "save selected". */
    function renderRows(dialog, closeDialog, rows, truncated, banner) {
        while (dialog.children.length > 1) {
            dialog.removeChild(dialog.lastChild);
        }

        const checkboxes = {};
        const dictionaryExtras = {};
        const fieldsByRow = {};
        const rowsBox = h('div', {class: 'rows'});

        rows.forEach(function (row) {
            const card = h('div', {class: 'row-card' + (row.ambiguous ? ' amb' : '')});
            const include = h('input', {type: 'checkbox'});
            include.checked = row.targets.length > 0 || row.noStorage;
            checkboxes[row.id] = include;
            card.appendChild(h('div', {class: 'row-head'}, include, h('span', {class: 'src', title: row.text, text: row.text})));

            if (row.known) {
                const knownText = config.languages
                    .filter(function (l) { return row.missing.indexOf(l.code) === -1 && row.known[l.code] && row.known[l.code].trim(); })
                    .map(function (l) { return l.name + ': ' + row.known[l.code]; })
                    .join(' · ');
                if (knownText) {
                    card.appendChild(h('div', {class: 'row-known', text: knownText}));
                }
            }

            const fields = {};
            const fieldsBox = h('div', {class: 'row-fields'});
            row.missing.forEach(function (code) {
                const language = config.languages.filter(function (l) { return l.code === code; })[0];
                if (!language) {
                    return;
                }
                const input = h('textarea', {rows: 1, dir: language.rtl ? 'rtl' : 'ltr', lang: code});
                input.value = row.values[code] || '';
                fields[code] = input;
                fieldsBox.appendChild(h('div', {class: 'rf'}, h('label', {text: language.name}), input));
            });
            fieldsByRow[row.id] = fields;
            card.appendChild(fieldsBox);

            if (row.aiFailed) {
                card.appendChild(h('div', {class: 'row-note', text: strings.pageFailedRow}));
            }
            if (row.noStorage) {
                card.appendChild(h('div', {class: 'row-note', text: strings.noSource}));
            } else if (row.ambiguous) {
                const extra = h('input', {type: 'checkbox'});
                dictionaryExtras[row.id] = extra;
                card.appendChild(h('label', {class: 'row-extra'}, extra, h('span', {text: strings.alsoDictionary})));
            }

            rowsBox.appendChild(card);
        });

        const countLabel = (strings.pageCount || '{n}').replace('{n}', String(rows.length))
            + (truncated ? ' · ' + (strings.pageTruncated || '').replace('{n}', String(config.scanLimit || 60)) : '');
        const selectAll = h('a', {href: '#', text: strings.pageSelectAll});
        selectAll.addEventListener('click', function (event) {
            event.preventDefault();
            Object.keys(checkboxes).forEach(function (id) { checkboxes[id].checked = true; });
        });
        const deselectAll = h('a', {href: '#', text: strings.pageDeselectAll});
        deselectAll.addEventListener('click', function (event) {
            event.preventDefault();
            Object.keys(checkboxes).forEach(function (id) { checkboxes[id].checked = false; });
        });

        const error = h('div', {class: 'error', hidden: ''});
        const status = h('span', {class: 'hint'});
        const save = h('button', {class: 'btn primary', type: 'button', text: strings.pageSaveSelected});
        const cancel = h('button', {class: 'btn', type: 'button', text: strings.cancel});

        const body = h('div', {class: 'body'});
        if (banner) {
            body.appendChild(h('div', {class: 'banner warn', text: banner}));
        }
        body.appendChild(h('div', {class: 'page-toolbar'}, h('span', {class: 'page-status', text: countLabel}), selectAll, deselectAll));
        body.appendChild(rowsBox);
        body.appendChild(error);
        dialog.appendChild(body);
        dialog.appendChild(h('footer', {}, status, cancel, save));

        function saveRow(row, i) {
            const card = rowsBox.children[i];
            const values = {};
            config.languages.forEach(function (l) { values[l.code] = ''; });
            row.missing.forEach(function (code) {
                const field = fieldsByRow[row.id][code];
                if (field) {
                    values[code] = field.value;
                }
            });
            const dictionary = row.noStorage || (dictionaryExtras[row.id] && dictionaryExtras[row.id].checked);
            const hasValue = Object.keys(values).some(function (code) { return values[code].trim() !== ''; });
            // Nothing was typed (Gemini failed and the admin left it blank): an empty translation cannot be saved
            // anywhere, dictionary or not (the save endpoint refuses it too) - skip instead of a doomed round trip.
            if (!hasValue) {
                card.classList.add('saved');
                return Promise.resolve(true);
            }
            // Ambiguous row (several places share this text), included but without "also save as a site-wide
            // translation": there is nowhere chosen to write it, same case the single popup blocks before sending.
            if (!dictionary && !row.targets.length) {
                card.classList.add('amb');
                card.appendChild(h('div', {class: 'row-msg', text: strings.nothingToSave}));
                return Promise.resolve(false);
            }
            return post('save', {text: row.text, values: values, targets: row.targets, dictionary: dictionary ? '1' : '0'})
                .then(function (response) {
                    if (!response.success) {
                        const failed = ((response.data && response.data.results) || []).filter(function (r) { return !r.ok; });
                        throw new Error(failed.length ? failed[0].message : strings.error);
                    }
                    const result = response.data;
                    if (result.replacement) {
                        runtime.replace(row.text, result.replacement);
                    }
                    if (result.runtime) {
                        runtime.add(result.runtime);
                    }
                    card.classList.add('saved');
                    return true;
                })
                .catch(function (problem) {
                    card.classList.add('amb');
                    card.appendChild(h('div', {class: 'row-msg', text: (problem && problem.message) || strings.error}));
                    return false;
                });
        }

        function submitAll() {
            const toSave = rows
                .map(function (row, i) { return {row: row, i: i}; })
                .filter(function (entry) { return checkboxes[entry.row.id].checked; });
            if (!toSave.length) {
                error.hidden = false;
                error.textContent = strings.nothingToSave;
                return;
            }
            error.hidden = true;
            save.disabled = true;
            cancel.disabled = true;
            let ok = 0;

            function next(index) {
                if (index >= toSave.length) {
                    save.disabled = false;
                    cancel.disabled = false;
                    save.textContent = strings.pageSaveSelected;
                    status.textContent = (strings.pageDone || '').replace('{ok}', String(ok)).replace('{total}', String(toSave.length));
                    return;
                }
                save.textContent = strings.pageSaving;
                status.textContent = strings.pageSaving + ' (' + (index + 1) + '/' + toSave.length + ')';
                saveRow(toSave[index].row, toSave[index].i).then(function (success) {
                    if (success) {
                        ok++;
                    }
                    next(index + 1);
                });
            }

            next(0);
        }

        save.addEventListener('click', submitAll);
        cancel.addEventListener('click', closeDialog);
    }

    /** Entry point of the "Translate page" button: scan, resolve, translate the missing languages, then review. */
    function openPageDialog() {
        if (popupOpen) {
            return;
        }
        popupOpen = true;
        hideHighlight();

        const dialog = h('div', {class: 'dialog page-dialog', role: 'dialog', 'aria-modal': 'true', dir: pageDirection()},
            h('header', {}, h('h2', {text: strings.pageTitle || 'Translate page'}), h('button', {class: 'x', type: 'button', 'aria-label': strings.cancel, text: '×'})),
            h('div', {class: 'loading', text: strings.pageScanning})
        );
        const backdrop = h('div', {class: 'backdrop'}, dialog);

        function closeDialog() {
            backdrop.remove();
            popupOpen = false;
        }

        backdrop.addEventListener('mousedown', function (event) {
            if (event.target === backdrop) {
                closeDialog();
            }
        });
        dialog.querySelector('.x').addEventListener('click', closeDialog);
        root.appendChild(backdrop);

        function showMessage(message) {
            while (dialog.children.length > 1) {
                dialog.removeChild(dialog.lastChild);
            }
            dialog.appendChild(h('div', {class: 'body'}, h('div', {class: 'note', text: message})));
            const footer = h('footer', {}, h('button', {class: 'btn', type: 'button', text: strings.cancel}));
            footer.querySelector('.btn').addEventListener('click', closeDialog);
            dialog.appendChild(footer);
        }

        const scanned = scanPage();
        if (!scanned.length) {
            showMessage(strings.pageNothing);
            return;
        }
        const truncated = scanned.length >= (config.scanLimit || 60);

        post('resolve-many', {texts: scanned.map(function (item) { return item.text; })})
            .then(function (response) {
                if (!response.success) {
                    throw new Error('resolve-many');
                }
                const rows = buildRows(response.data.items || []);
                if (!rows.length) {
                    showMessage(strings.pageNothing);
                    return;
                }
                return translateRows(dialog, rows).then(function (banner) {
                    renderRows(dialog, closeDialog, rows, truncated, banner);
                });
            })
            .catch(function () {
                showMessage(strings.error);
            });
    }

    /* ------------------------------------------------------------------ start */

    function init() {
        buildShell();

        // Closed from the dashboard in another tab: the site drops its button (and the mode) right away.
        window.addEventListener('storage', function (event) {
            if (event.key === BUTTON_KEY) {
                applyButtonVisibility();
            }
        });
        applyButtonVisibility();

        try {
            if (sessionStorage.getItem(STORAGE_KEY) === '1' && (config.isAdmin || buttonEnabled())) {
                if (config.isAdmin) {
                    saveButtonEnabled(true);
                }
                setActive(true);
            }
        } catch (e) { /* storage may be blocked */ }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
