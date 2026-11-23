/* Shared helpers for the editor: config, API calls, DOM, icons, toast, confirm dialog. */

export const config = JSON.parse(document.getElementById('ve-admin-config')?.textContent || '{}');

/* ---------- API ---------- */

export async function api(action, { body, form, params } = {}) {
    const url = new URL(config.api, location.href);
    url.searchParams.set('action', action);
    for (const [k, v] of Object.entries(params || {})) url.searchParams.set(k, v);

    const opts = { method: body || form ? 'POST' : 'GET', headers: { 'X-VE-CSRF': config.csrf }, credentials: 'same-origin' };
    if (form) opts.body = form;
    else if (body) {
        opts.headers['Content-Type'] = 'application/json';
        opts.body = JSON.stringify(body);
    }

    let res, data;
    try {
        res = await fetch(url, opts);
        data = await res.json();
    } catch {
        throw new Error(res ? `Server error (${res.status}).` : 'No connection to the server.');
    }
    if (!res.ok) {
        const err = new Error(data?.error || `Request failed (${res.status}).`);
        err.data = data;
        throw err;
    }
    return data;
}

/* ---------- DOM ---------- */

/** el('button.ve-admin-btn', {title: 'x', onclick}, child, …) */
export function el(spec, attrs = {}, ...children) {
    const [tag, ...classes] = spec.split('.');
    const node = document.createElement(tag || 'div');
    if (classes.length) node.className = classes.join(' ');
    for (const [k, v] of Object.entries(attrs || {})) {
        if (v == null || v === false) continue;
        if (k.startsWith('on')) node.addEventListener(k.slice(2), v);
        else if (k === 'html') node.innerHTML = v;
        else if (k === 'dataset') Object.assign(node.dataset, v);
        else node.setAttribute(k, v === true ? '' : v);
    }
    for (const c of children.flat()) {
        if (c == null || c === false) continue;
        node.append(c instanceof Node ? c : document.createTextNode(String(c)));
    }
    return node;
}

/** Parse server-rendered item HTML into one element. */
export function fromHTML(html) {
    const t = document.createElement('template');
    t.innerHTML = html.trim();
    return t.content.firstElementChild;
}

/* ---------- icons ----------
   Tiny pixel-art icons drawn on a 9x9 grid ('#' = pixel), rendered at 2x.
   Swap for Pixelarticons later if wanted: only this table changes. */

const PIXELS = {
    edit:   ['......##.', '.....#..#', '....#..#.', '...#..#..', '..#..#...', '.#..#....', '.#.#.....', '.##......', '.........'],
    plus:   ['.........', '....#....', '....#....', '....#....', '.#######.', '....#....', '....#....', '....#....', '.........'],
    close:  ['.........', '.#.....#.', '..#...#..', '...#.#...', '....#....', '...#.#...', '..#...#..', '.#.....#.', '.........'],
    trash:  ['...###...', '#########', '.........', '.#######.', '.#.#.#.#.', '.#.#.#.#.', '.#.#.#.#.', '.#.#.#.#.', '.#######.'],
    copy:   ['..######.', '..#....#.', '######.#.', '#....#.#.', '#....#.#.', '#....#.#.', '#....###.', '#....#...', '######...'],
    image:  ['#########', '#.......#', '#.##....#', '#.##....#', '#.....#.#', '#....####', '#...#####', '#..######', '#########'],
    video:  ['#########', '#.......#', '#..#....#', '#..##...#', '#..###..#', '#..##...#', '#..#....#', '#.......#', '#########'],
    up:     ['....#....', '...###...', '..#.#.#..', '.#..#..#.', '....#....', '....#....', '....#....', '....#....', '....#....'],
    upload: ['....#....', '...###...', '..#.#.#..', '....#....', '....#....', '#...#...#', '#.......#', '#.......#', '#########'],
    page:   ['.#####...', '.#...##..', '.#...###.', '.#.....#.', '.#.###.#.', '.#.....#.', '.#.###.#.', '.#.....#.', '.#######.'],
    logout: ['#####....', '#........', '#...#....', '#....#...', '#.######.', '#....#...', '#...#....', '#........', '#####....'],
    check:  ['.........', '........#', '.......#.', '......#..', '#....#...', '.#..#....', '..##.....', '.........', '.........'],
    style:  ['.......##', '......###', '.....###.', '....###..', '...###...', '.###.....', '####.....', '###......', '##.......'],
    site:   ['#########', '#.#.#...#', '#########', '#.......#', '#.......#', '#.......#', '#.......#', '#.......#', '#########'],
    play:   ['.........', '..#......', '..##.....', '..###....', '..####...', '..###....', '..##.....', '..#......', '.........'],
    link:   ['.........', '.....##..', '....#..#.', '...#.#.#.', '..#.#.#..', '.#.#.#...', '.#..#....', '..##.....', '.........'],
};

export function icon(name) {
    const rows = PIXELS[name] || PIXELS.close;
    let d = '';
    rows.forEach((row, y) => [...row].forEach((ch, x) => { if (ch === '#' && x < 9) d += `M${x} ${y}h1v1h-1z`; }));
    const svg = `<svg class="ve-admin-icon" viewBox="0 0 9 9" width="18" height="18" aria-hidden="true" shape-rendering="crispEdges"><path fill="currentColor" d="${d}"/></svg>`;
    return fromHTML(svg);
}

/** Icon button. label is used for the tooltip and screen readers. */
export function iconButton(name, label, onclick, extra = '') {
    return el('button.ve-admin-btn.ve-admin-icon-btn' + (extra ? '.' + extra : ''), { type: 'button', title: label, 'aria-label': label, onclick }, icon(name));
}

/* ---------- toast ---------- */

let toastTimer;
export function toast(message, type = 'info') {
    let box = document.getElementById('ve-admin-toast');
    if (!box) {
        box = el('div.ve-admin-ui.ve-admin-toast', { id: 've-admin-toast', role: 'status', 'aria-live': 'polite' });
        document.body.append(box);
    }
    box.textContent = message;
    box.dataset.type = type;
    box.classList.add('ve-admin-show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => box.classList.remove('ve-admin-show'), type === 'error' ? 6000 : 2200);
}

/* ---------- confirm (destructive actions only) ---------- */

export function confirmDialog({ title, text = '', confirm = 'Delete', danger = true }) {
    return new Promise(resolve => {
        const dlg = el('dialog.ve-admin-ui.ve-admin-dialog', { 'aria-labelledby': 've-admin-dialog-title' },
            el('h2', { id: 've-admin-dialog-title' }, title),
            text ? el('p', {}, text) : null,
            el('div.ve-admin-dialog-actions', {},
                el('button.ve-admin-btn', { type: 'button', value: 'cancel', onclick: () => dlg.close('cancel') }, 'Cancel'),
                el('button.ve-admin-btn' + (danger ? '.ve-admin-btn-danger' : '.ve-admin-btn-primary'), { type: 'button', onclick: () => dlg.close('ok') }, confirm),
            ),
        );
        dlg.addEventListener('close', () => { resolve(dlg.returnValue === 'ok'); dlg.remove(); });
        document.body.append(dlg);
        dlg.showModal();
        dlg.querySelector('button[value=cancel]').focus(); // safe default
    });
}

/* ---------- drawer (side panel used by media tray and page settings) ---------- */

export function drawer(title, { onclose } = {}) {
    document.querySelectorAll('.ve-admin-drawer').forEach(d => d._close?.());
    const body = el('div.ve-admin-drawer-body');
    const head = el('div.ve-admin-drawer-head', {}, el('h2', {}, title));
    const box = el('aside.ve-admin-ui.ve-admin-drawer', { 'aria-label': title }, head, body);
    const close = () => {
        box.remove();
        document.removeEventListener('keydown', onKey);
        onclose?.();
    };
    const onKey = e => { if (e.key === 'Escape' && !document.querySelector('.ve-admin-dialog[open]')) close(); };
    head.append(iconButton('close', 'Close', close));
    box._close = close;
    document.addEventListener('keydown', onKey);
    document.body.append(box);
    return { box, head, body, close, setTitle: t => { head.querySelector('h2').textContent = t; } };
}
