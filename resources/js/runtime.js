/**
 * Live Translate - runtime.
 *
 * Replaces, in the browser, the visible texts that have a site-wide translation (the dictionary): texts that are typed
 * straight into the views and are not stored anywhere else. Also used by the editor to update the page right after
 * a save.
 *
 * norm() must stay in sync with TrueVs\AiTranslator\Support\Text::normalize().
 */
(function () {
    'use strict';

    const config = window.LT_RUNTIME || {};
    const map = Object.assign({}, config.map || {});
    const SKIP_SELECTOR = 'script,style,noscript,textarea,pre,code,select,[contenteditable=""],[contenteditable="true"],[data-lt-skip]';
    const ATTRIBUTES = ['placeholder', 'title', 'alt', 'aria-label', 'data-confirm'];
    const BUTTON_TYPES = ['submit', 'button', 'reset'];

    function norm(text) {
        return String(text)
            .replace(/[‘’]/g, "'")
            .replace(/[“”]/g, '"')
            .replace(/[–—]/g, '-')
            .replace(/…/g, '...')
            .replace(/ /g, ' ')
            .replace(/\s+/g, ' ')
            .trim();
    }

    function isSkipped(element) {
        return !element || !!(element.closest && element.closest(SKIP_SELECTOR));
    }

    /** Replace a text keeping its leading/trailing whitespace; returns null when there is nothing to change. */
    function translateValue(value, lookup) {
        const parts = /^(\s*)([\s\S]*?)(\s*)$/.exec(value);
        const replacement = lookup(norm(parts[2]));
        return replacement === undefined || replacement === parts[2] ? null : parts[1] + replacement + parts[3];
    }

    function applyTo(root, lookup) {
        if (!root) {
            return;
        }
        const base = root.nodeType === 1 ? root : root.parentElement;
        if (isSkipped(base)) {
            return;
        }

        if (root.nodeType === 3) {
            const changed = translateValue(root.nodeValue, lookup);
            if (changed !== null) {
                root.nodeValue = changed;
            }
            return;
        }
        if (root.nodeType !== 1 && root.nodeType !== 9 && root.nodeType !== 11) {
            return;
        }

        const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
            acceptNode: function (node) {
                return node.nodeValue.trim() === '' || isSkipped(node.parentElement) ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT;
            }
        });
        const nodes = [];
        while (walker.nextNode()) {
            nodes.push(walker.currentNode);
        }
        nodes.forEach(function (node) {
            const changed = translateValue(node.nodeValue, lookup);
            if (changed !== null) {
                node.nodeValue = changed;
            }
        });

        if (root.querySelectorAll) {
            root.querySelectorAll('[placeholder],[title],[alt],[aria-label],[data-confirm],input[type="submit"],input[type="button"],input[type="reset"]').forEach(function (element) {
                if (isSkipped(element)) {
                    return;
                }
                ATTRIBUTES.forEach(function (attribute) {
                    if (element.hasAttribute(attribute)) {
                        const changed = translateValue(element.getAttribute(attribute), lookup);
                        if (changed !== null) {
                            element.setAttribute(attribute, changed);
                        }
                    }
                });
                if (element.tagName === 'INPUT' && BUTTON_TYPES.indexOf(element.type) !== -1) {
                    const changed = translateValue(element.value, lookup);
                    if (changed !== null) {
                        element.value = changed;
                    }
                }
            });
        }
    }

    function fromMap(key) {
        return Object.prototype.hasOwnProperty.call(map, key) ? map[key] : undefined;
    }

    /** The tab title is outside <body>. */
    function translateTitle(lookup) {
        const changed = translateValue(document.title, lookup);
        if (changed !== null) {
            document.title = changed;
        }
    }

    const api = {
        norm: norm,
        isSkipped: isSkipped,
        /** Add site-wide translations (norm(text) => translation) and translate the page with them. */
        add: function (extra) {
            Object.assign(map, extra || {});
            applyTo(document.body, fromMap);
            translateTitle(fromMap);
        },
        /** Replace every visible occurrence of `from` by `to` (no persistence, used after a save). */
        replace: function (from, to) {
            const key = norm(from);
            const lookup = function (value) {
                return value === key ? to : undefined;
            };
            applyTo(document.body, lookup);
            translateTitle(lookup);
        }
    };
    window.LTRuntime = api;

    function start() {
        if (!Object.keys(map).length) {
            return;
        }
        applyTo(document.body, fromMap);
        translateTitle(fromMap);

        // Content added later (menus, sliders, AJAX): translate only what was added.
        let queue = [];
        let scheduled = false;
        new MutationObserver(function (mutations) {
            mutations.forEach(function (mutation) {
                mutation.addedNodes.forEach(function (node) {
                    queue.push(node);
                });
            });
            if (!scheduled && queue.length) {
                scheduled = true;
                setTimeout(function () {
                    const nodes = queue;
                    queue = [];
                    scheduled = false;
                    nodes.forEach(function (node) {
                        if (node.isConnected) {
                            applyTo(node, fromMap);
                        }
                    });
                }, 60);
            }
        }).observe(document.body, {childList: true, subtree: true});
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
}());
