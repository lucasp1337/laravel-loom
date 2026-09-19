/* Loom UI behaviour: shell shortcuts, command palette, copy buttons, chain canvas.
   Alpine ships with Livewire; components register on `alpine:init`. */
(function () {
    'use strict';

    function register(Alpine) {
        var isMac = /Mac|iPhone|iPad/i.test(navigator.platform || '');

        Alpine.data('loomShell', function () {
            return {
                drawer: false,
                palette: false,
                pending: false,
                timer: null,
                cursor: 0,
                go: {},
                mod: isMac ? '⌘K' : 'Ctrl K',

                init: function () {
                    var self = this;
                    try { this.go = JSON.parse(this.$el.dataset.go || '{}'); } catch (e) { this.go = {}; }
                    // Livewire swaps the result list on every keystroke; re-mark the cursor row.
                    new MutationObserver(function () { self.apply(); })
                        .observe(this.$refs.palette, { childList: true, subtree: true });
                    this.$watch('cursor', function () { self.apply(); });
                },

                items: function () {
                    return Array.prototype.slice.call(this.$refs.palette.querySelectorAll('[data-palette-item]'));
                },

                apply: function () {
                    var items = this.items();
                    if (this.cursor >= items.length) { this.cursor = Math.max(0, items.length - 1); }
                    for (var i = 0; i < items.length; i++) {
                        var on = i === this.cursor;
                        if (items[i].dataset.active !== String(on)) { items[i].dataset.active = String(on); }
                        if (on) { items[i].scrollIntoView({ block: 'nearest' }); }
                    }
                },

                openPalette: function () {
                    var self = this;
                    this.palette = true;
                    this.cursor = 0;
                    this.$nextTick(function () {
                        var input = self.$refs.palette.querySelector('[data-palette-input]');
                        if (input) { input.focus(); input.select(); }
                    });
                },

                closePalette: function () { this.palette = false; },

                move: function (delta) {
                    var max = this.items().length - 1;
                    this.cursor = Math.max(0, Math.min(max, this.cursor + delta));
                },

                choose: function () {
                    var item = this.items()[this.cursor];
                    if (item) { item.click(); }
                },

                onKey: function (e) {
                    var t = e.target;
                    var typing = t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.isContentEditable);

                    if ((e.metaKey || e.ctrlKey) && String(e.key).toLowerCase() === 'k') {
                        e.preventDefault();
                        this.openPalette();
                        return;
                    }
                    if (e.key === 'Escape') {
                        if (this.palette) { this.closePalette(); } else { this.drawer = false; window.dispatchEvent(new CustomEvent('loom-escape')); }
                        return;
                    }
                    if (typing || e.metaKey || e.ctrlKey || e.altKey) { return; }
                    if (e.key === '/') { e.preventDefault(); this.openPalette(); return; }

                    if (this.pending) {
                        this.pending = false;
                        clearTimeout(this.timer);
                        var url = this.go[e.key];
                        if (url) { e.preventDefault(); window.location.href = url; }
                        return;
                    }
                    if (e.key === 'g') {
                        var self = this;
                        this.pending = true;
                        this.timer = setTimeout(function () { self.pending = false; }, 900);
                    }
                }
            };
        });

        Alpine.data('loomCopy', function (text) {
            return {
                label: 'Copy',
                copy: function () {
                    var self = this;
                    var done = function () {
                        self.label = 'Copied';
                        setTimeout(function () { self.label = 'Copy'; }, 1400);
                    };
                    // Clipboard API throws outside secure contexts; fall back to a hidden textarea.
                    try {
                        navigator.clipboard.writeText(text).then(done, function () { self.fallback(text, done); });
                    } catch (e) {
                        this.fallback(text, done);
                    }
                },
                fallback: function (value, done) {
                    var area = document.createElement('textarea');
                    area.value = value;
                    area.setAttribute('readonly', '');
                    area.style.position = 'fixed';
                    area.style.opacity = '0';
                    document.body.appendChild(area);
                    area.select();
                    try { document.execCommand('copy'); done(); } catch (e) { /* select-only fallback */ }
                    document.body.removeChild(area);
                }
            };
        });

        var PALETTE = {
            event: ['--accent', '--accentSub', '--accentBd'],
            listener: ['--fg', '--bgSub', '--bd'],
            observer: ['--done', '--doneSub', '--done'],
            closure: ['--fgM', '--neutral', '--bd'],
            job: ['--success', '--bgSub', '--success'],
            mailable: ['--attn', '--attnSub', '--attnBd'],
            notification: ['--attn', '--attnSub', '--attnBd'],
            route: ['--fgM', '--bgSub', '--bd'],
            cycle: ['--fgM', '--neutral', '--bd']
        };

        var FALLBACK = {
            '--accent': '#0969da', '--accentSub': '#ddf4ff', '--accentBd': '#54aeff', '--fg': '#1f2328',
            '--fgM': '#59636e', '--bgSub': '#f6f8fa', '--bd': '#d1d9e0', '--done': '#8250df', '--doneSub': '#fbefff',
            '--neutral': '#eaeef2', '--success': '#1a7f37', '--attn': '#9a6700', '--attnSub': '#fff8c5', '--attnBd': '#d4a72c'
        };

        Alpine.data('loomChain', function () {
            return {
                cy: null,
                sig: '',
                ready: false,
                missing: false,
                observers: [],
                mq: null,
                onTheme: null,

                init: function () {
                    var self = this;
                    if (!window.cytoscape) { this.missing = true; return; }

                    var watch = function (node, options) {
                        var o = new MutationObserver(function () { self.sync(); });
                        o.observe(node, options);
                        self.observers.push(o);
                    };
                    watch(this.$el, { attributes: true, attributeFilter: ['data-graph'] });

                    this.onTheme = function () { self.sync(true); };
                    this.mq = window.matchMedia('(prefers-color-scheme: dark)');
                    this.mq.addEventListener('change', this.onTheme);
                    var themeObserver = new MutationObserver(this.onTheme);
                    themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
                    this.observers.push(themeObserver);

                    this.sync(true);
                },

                destroy: function () {
                    this.observers.forEach(function (o) { o.disconnect(); });
                    if (this.mq && this.onTheme) { this.mq.removeEventListener('change', this.onTheme); }
                    if (this.cy) { this.cy.destroy(); this.cy = null; }
                },

                graph: function () {
                    try { return JSON.parse(this.$el.dataset.graph); } catch (e) { return null; }
                },

                // Rebuild only when the structure changes; a selection change restyles in place.
                sync: function (force) {
                    var g = this.graph();
                    if (!g) { return; }
                    var sig = JSON.stringify(g.nodes.map(function (n) { return [n.key, n.collapsed]; }));
                    if (force || !this.cy || sig !== this.sig) {
                        this.sig = sig;
                        this.build(g);
                    } else {
                        this.select(g);
                    }
                },

                select: function (g) {
                    var cy = this.cy;
                    g.nodes.forEach(function (n) {
                        var el = cy.getElementById(n.key);
                        if (el.length) { el.data('sel', n.selected ? 1 : 0); }
                    });
                },

                fit: function () { if (this.cy) { this.cy.fit(undefined, 36); } },

                build: function (g) {
                    var self = this;
                    var css = getComputedStyle(this.$refs.canvas);
                    var v = function (name) { return css.getPropertyValue(name).trim() || FALLBACK[name]; };

                    var columns = {};
                    var nodes = g.nodes.map(function (n) {
                        var col = columns[n.depth] = (columns[n.depth] || []);
                        n.row = col.length;
                        col.push(n.key);
                        return n;
                    });
                    var elements = nodes.map(function (n) {
                        var col = columns[n.depth];
                        return {
                            group: 'nodes',
                            data: {
                                id: n.key, gid: n.id, type: n.type, label: n.label + (n.collapsed ? ' ⊕' : ''),
                                collapsed: n.collapsed ? 1 : 0, hasChildren: n.hasChildren ? 1 : 0, sel: n.selected ? 1 : 0
                            },
                            position: { x: n.depth * 210, y: (n.row - (col.length - 1) / 2) * 74 }
                        };
                    }).concat(g.edges.map(function (e) {
                        return { group: 'edges', data: { id: e.s + '=>' + e.t, source: e.s, target: e.t } };
                    }));

                    var style = [
                        { selector: 'node', style: {
                            shape: 'round-rectangle', width: 168, height: 46, 'border-width': 1, label: 'data(label)',
                            'text-wrap': 'wrap', 'text-max-width': 148, 'text-valign': 'center', 'text-halign': 'center',
                            'font-family': css.getPropertyValue('--mono').trim() || 'monospace', 'font-size': 10.5,
                            'line-height': 1.45, color: v('--fg'), 'overlay-opacity': 0
                        } },
                        { selector: 'edge', style: {
                            width: 1, 'line-color': v('--bd'), 'curve-style': 'bezier', 'target-arrow-shape': 'triangle',
                            'target-arrow-color': v('--bd'), 'arrow-scale': 0.8
                        } },
                        { selector: 'node[type = "cycle"]', style: { height: 40, 'border-style': 'dashed', color: v('--fgM') } },
                        { selector: 'node[collapsed = 1]', style: { 'border-style': 'double', 'border-width': 3 } },
                        { selector: 'node[sel = 1]', style: { 'border-width': 2, 'border-color': v('--accent') } }
                    ];
                    Object.keys(PALETTE).forEach(function (type) {
                        var p = PALETTE[type];
                        style.unshift({ selector: 'node[type = "' + type + '"]', style: {
                            color: type === 'cycle' ? v('--fgM') : v('--fg'),
                            'background-color': v(p[1]), 'border-color': v(p[2])
                        } });
                    });

                    if (this.cy) { this.cy.destroy(); }
                    var cy = this.cy = window.cytoscape({
                        container: this.$refs.canvas,
                        elements: elements,
                        style: style,
                        layout: { name: 'preset', fit: true, padding: 36 },
                        wheelSensitivity: 0.2, minZoom: 0.3, maxZoom: 2
                    });

                    cy.on('tap', 'node', function (evt) { self.$wire.select(evt.target.id()); });
                    cy.on('tap', function (evt) { if (evt.target === cy) { self.$wire.select(null); } });
                    cy.on('dbltap', 'node', function (evt) {
                        if (evt.target.data('hasChildren')) { self.$wire.toggle(evt.target.id()); }
                    });
                    this.ready = true;
                }
            };
        });
    }

    if (window.Alpine) { register(window.Alpine); } else { document.addEventListener('alpine:init', function () { register(window.Alpine); }); }
})();
