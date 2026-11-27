/* Styling pickers (Phase 2). Everything here is built from site/styles.manifest.json,
   served by the API as style.config: groups -> chips, palette -> swatches, options -> chips. */

import { api, el, icon, toast, drawer } from './util.js';

let configPromise = null;
export function styleConfig(refresh = false) {
    if (refresh || !configPromise) configPromise = api('style.config').catch(e => { configPromise = null; throw e; });
    return configPromise;
}

/* ---------- building blocks ---------- */

/** A labelled section of the panel. */
export function section(title, ...children) {
    return el('section.ve-admin-style-section', {}, el('h3', {}, title), ...children);
}

/**
 * Chips for one manifest group. classes = Set of current classes.
 * Exclusive groups get a "None" chip and allow one pick; others toggle.
 */
export function groupChips(group, classes, onChange) {
    const own = group.classes.map(c => c.class);
    const pick = cls => {
        const next = new Set(classes);
        if (group.exclusive) {
            own.forEach(c => next.delete(c));
            if (cls) next.add(cls);
        } else if (next.has(cls)) next.delete(cls);
        else next.add(cls);
        onChange(next, group);
    };
    const chip = (text, cls, on) => el('button.ve-admin-chip', { type: 'button', 'aria-pressed': String(on), onclick: () => pick(cls) }, text);
    const chips = group.classes.map(c => chip(c.label, c.class, classes.has(c.class)));
    if (group.exclusive) chips.unshift(chip('None', null, !own.some(c => classes.has(c))));
    return el('div.ve-admin-chips', { role: 'group', 'aria-label': group.label }, chips);
}

/** Generic single-choice chips: choices = [{value, label}]. */
export function choiceChips(label, choices, current, onPick) {
    return el('div.ve-admin-chips', { role: 'group', 'aria-label': label }, choices.map(c =>
        el('button.ve-admin-chip', { type: 'button', 'aria-pressed': String(c.value === current), onclick: () => onPick(c.value) }, c.label)));
}

/** Palette swatches; '' = none / default. */
export function swatches(label, palette, current, onPick, noneLabel = 'None') {
    const sw = (token, text) => el('button.ve-admin-swatch', {
        type: 'button', title: text, 'aria-label': `${label}: ${text}`, 'aria-pressed': String((current || '') === token),
        onclick: () => onPick(token),
    }, el('span', { style: token ? `background: var(--color-${token})` : null, class: token ? null : 've-admin-swatch-none' }));
    return el('div.ve-admin-swatches', { role: 'group', 'aria-label': label },
        sw('', noneLabel), palette.map(p => sw(p.token, p.label)));
}

/* ---------- item style panel ---------- */

const TYPE_LABEL = { section: 'section', row: 'row', col: 'column', heading: 'heading', text: 'text', image: 'image', video: 'video' };

/**
 * Open the style panel for an item.
 *   run(task)          the editor's save queue (one change at a time)
 *   rendered(html, g)  replace the item on the page with fresh HTML (g = group that changed)
 *   preview()          replay the item's animation
 */
export async function openItemStyle(id, { run, rendered, preview }) {
    const d = drawer('Style');
    d.box.classList.add('ve-admin-style-drawer');
    d.body.append(el('p.ve-admin-muted', {}, 'Loading…'));

    let cfg, item;
    try {
        [cfg, item] = await Promise.all([styleConfig(), api('item.get', { params: { id } }).then(r => r.item)]);
    } catch (e) {
        d.body.replaceChildren(el('p.ve-admin-error', {}, e.message));
        return;
    }
    d.setTitle(`Style: ${TYPE_LABEL[item.type] || item.type}`);

    const save = (patch, group) => run(() => api('item.style', { body: { id, ...patch } }))
        .then(r => { item = r.item; rendered(r.html, group); render(); }, () => render());

    const render = () => {
        const classes = new Set(item.classes.split(/\s+/).filter(Boolean));
        const groups = cfg.groups.filter(g => g.applies.includes(item.type));
        const parts = [];

        // Colours first: the most visible change.
        parts.push(section('Background', swatches('Background', cfg.palette, item.settings.bg, v => save({ settings: { bg: v } }))));
        parts.push(section(item.type === 'image' || item.type === 'video' ? 'Text colour (caption)' : 'Text colour',
            swatches('Text colour', cfg.palette, item.settings.color, v => save({ settings: { color: v } }), 'Default')));

        for (const g of groups) {
            const chips = groupChips(g, classes, (next, group) => save({ classes: [...next].join(' ') }, group));
            const extra = g.id === 'animation' && groups.some(x => x.id === 'animation') && [...classes].some(c => c.startsWith('anim-'))
                ? el('button.ve-admin-btn.ve-admin-play', { type: 'button', onclick: preview }, icon('play'), 'Play')
                : null;
            parts.push(section(g.label, chips, extra));
        }

        if (item.type === 'image') parts.push(focalSection());
        d.body.replaceChildren(...parts);
    };

    const focalSection = () => {
        if (!item.media) return section('Focal point', el('p.ve-admin-muted', {}, 'Choose an image first.'));
        const focal = (item.content.focal || '50% 50%').split(' ').map(parseFloat);
        const dot = el('span.ve-admin-focal-dot', { style: `left:${focal[0]}%;top:${focal[1]}%` });
        const img = el('img', { src: item.media.thumb || item.media.url, alt: '', draggable: 'false' });
        const box = el('button.ve-admin-focal', { type: 'button', 'aria-label': 'Click the part of the image that must stay visible' }, img, dot);
        box.addEventListener('click', e => {
            const r = img.getBoundingClientRect();
            const x = Math.round(Math.min(100, Math.max(0, (e.clientX - r.left) / r.width * 100)));
            const y = Math.round(Math.min(100, Math.max(0, (e.clientY - r.top) / r.height * 100)));
            setFocal(`${x}% ${y}%`);
        });
        const setFocal = value => run(() => api('item.update', { body: { id, content: { focal: value } } }))
            .then(r => { item.content.focal = value || undefined; rendered(r.html); render(); }, () => {});
        const cropped = item.classes.split(/\s+/).some(c => c === 'fit-cover');
        return section('Focal point',
            box,
            el('div.ve-admin-row', {},
                el('small.ve-admin-muted', {}, cropped ? 'Click the part that must stay visible when the image is cropped.' : 'Used when Fit is “Fill (cover)”.'),
                item.content.focal ? el('button.ve-admin-btn', { type: 'button', onclick: () => setFocal(null) }, 'Reset') : null),
        );
    };

    render();
    return d;
}

/* ---------- site (global) settings panel ---------- */

const darkNow = mode => mode === 'dark' || (mode === 'auto' && matchMedia('(prefers-color-scheme: dark)').matches);

export async function openSitePanel() {
    const d = drawer('Site style');
    d.body.append(el('p.ve-admin-muted', {}, 'Loading…'));
    let cfg;
    try { cfg = await styleConfig(true); } catch (e) { d.body.replaceChildren(el('p.ve-admin-error', {}, e.message)); return; }
    let theme = cfg.theme;
    const root = document.documentElement;

    const save = async patch => {
        try {
            const r = await api('theme.update', { body: patch });
            theme = r.theme;
            document.getElementById('ve-theme').textContent = r.css;
            root.dataset.theme = r.mode;
            cfg.palette.forEach(p => root.style.removeProperty(`--color-${p.token}`)); // drop live previews
            configPromise = Promise.resolve({ ...cfg, theme });
            toast('Saved');
        } catch (e) { toast(e.message, 'error'); }
        render();
    };

    const colorInput = (p, mode) => {
        const input = el('input', { type: 'color', value: theme.colors[p.token][mode], 'aria-label': `${p.label}${mode === 'dark' ? ' (dark mode)' : ''}` });
        // Live preview while dragging; save once the colour is chosen.
        input.addEventListener('input', () => { if ((mode === 'dark') === darkNow(theme.mode)) root.style.setProperty(`--color-${p.token}`, input.value); });
        input.addEventListener('change', () => save({ colors: { [p.token]: { [mode]: input.value } } }));
        return input;
    };

    const render = () => {
        const hasDark = cfg.palette.some(p => p.dark);
        d.body.replaceChildren(
            section('Colour mode', choiceChips('Colour mode', [
                { value: 'auto', label: 'Follow the device' }, { value: 'light', label: 'Light' }, { value: 'dark', label: 'Dark' },
            ], theme.mode, v => save({ mode: v }))),
            section('Palette',
                el('div.ve-admin-palette', {},
                    hasDark ? el('div.ve-admin-palette-head', {}, el('span'), el('small.ve-admin-muted', {}, 'Light'), el('small.ve-admin-muted', {}, 'Dark')) : null,
                    cfg.palette.map(p => el('div.ve-admin-palette-row', {},
                        el('span', {}, p.label),
                        colorInput(p, 'light'),
                        p.dark ? colorInput(p, 'dark') : el('small.ve-admin-muted', { title: 'Same in both modes' }, '–'),
                    ))),
                el('small.ve-admin-muted', {}, 'Items and pages pick from these colours, so a change here restyles the whole site.')),
            ...cfg.options.map(o => section(o.label, choiceChips(o.label, o.choices, theme.options[o.var], v => save({ options: { [o.var]: v } })))),
            ...(cfg.components || []).map(c => componentBlock(c, cfg.palette, next => {
                cfg.components = cfg.components.map(x => x.family === next.family ? next : x);
                configPromise = Promise.resolve({ ...cfg, theme });
            })),
        );
    };
    render();
    return d;
}

/* ---------- component variants (header, menu button, navigation, footer) ---------- */

/**
 * Pickers for one installed component family, built only from what its
 * family.json / variant.json declare: the variant (when more than one is
 * installed), option groups and tokens of that variant.
 */
function componentBlock(comp, palette, onSaved) {
    const box = el('div.ve-admin-component');
    const knownClasses = [
        ...comp.variants.map(v => v.class),
        ...comp.groups.flatMap(g => g.classes.map(c => c.class)),
    ].flatMap(c => c.split(/\s+/)).filter(Boolean);

    const save = async patch => {
        try {
            const r = await api('components.update', { body: { family: comp.family, ...patch } });
            comp = { ...comp, state: r.state };
            document.getElementById('ve-components').textContent = r.css;
            // Live update of the rendered component: swap only the classes the family owns.
            document.querySelectorAll(`[data-ve-component="${comp.family}"]`).forEach(node => {
                node.classList.remove(...knownClasses);
                node.classList.add(...r.state.class.split(/\s+/).filter(Boolean));
            });
            onSaved(comp);
            toast('Saved');
        } catch (e) { toast(e.message, 'error'); }
        render();
    };

    const render = () => {
        const { state } = comp;
        const active = x => x.variant === null || x.variant === state.variant;
        const classes = new Set(state.classes);
        const parts = [el('h2.ve-admin-component-title', {}, comp.label)];

        if (comp.variants.length > 1) {
            parts.push(section('Style', choiceChips(comp.label, comp.variants.map(v => ({ value: v.id, label: v.label })),
                state.variant, v => save({ variant: v }))));
        }
        for (const g of comp.groups.filter(active)) {
            const pick = next => save({ classes: [...next].join(' ') });
            // A group with a default always has one option on: chips without "None".
            const chips = g.default
                ? choiceChips(g.label, g.classes.map(c => ({ value: c.class, label: c.label })),
                    g.classes.find(c => classes.has(c.class))?.class,
                    v => { const next = new Set(classes); g.classes.forEach(c => next.delete(c.class)); next.add(v); pick(next); })
                : groupChips(g, classes, pick);
            parts.push(section(g.label, chips));
        }
        for (const t of comp.tokens.filter(active)) {
            const current = state.tokens[t.var] || '';
            parts.push(section(t.label, t.type === 'color'
                ? swatches(t.label, palette, current, v => save({ tokens: { [t.var]: v || null } }), 'Default')
                : choiceChips(t.label, t.choices, current || t.default, v => save({ tokens: { [t.var]: v === t.default ? null : v } }))));
        }
        box.replaceChildren(...parts);
    };
    render();
    return box;
}
