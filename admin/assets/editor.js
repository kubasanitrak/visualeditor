/* Visual Editor: edit-in-place layer. Loaded only for a logged-in owner (admin/bootstrap.php).
   Finds items through data-ve-id / data-ve-type; all editor UI is namespaced ve-admin-*. */

import { api, config, el, icon, iconButton, fromHTML, toast, confirmDialog } from './js/util.js';
import { openTray } from './js/tray.js';
import { openPagePanel } from './js/page.js';

const html = document.documentElement;
const main = document.querySelector('.ve-main') || document.body;
const pageId = Number(document.body.dataset.vePage) || 0;
const STORE_KEY = 've-admin-editing';
const LABEL = { section: 'section', row: 'row', col: 'column', heading: 'heading', text: 'text', image: 'image', video: 'video' };
const CONTAINERS = ['section', 'row', 'col'];
const PLAIN = (() => { const d = document.createElement('div'); d.contentEditable = 'plaintext-only'; return d.contentEditable === 'plaintext-only'; })();

let editing = false;
let selected = null;
let components = [];

const find = id => main.querySelector(`[data-ve-id="${CSS.escape(String(id))}"]`);
const label = node => LABEL[node?.dataset.veType] || node?.dataset.veType || 'item';
const store = (k, v) => { try { localStorage.setItem(k, v); } catch {} };
const stored = k => { try { return localStorage.getItem(k); } catch { return null; } };

/* ---------- save queue: one change at a time, in order ---------- */

let chain = Promise.resolve();
let pending = 0;
const stateEl = el('span.ve-admin-save-state', { 'aria-live': 'polite' });

function queue(task) {
    pending++;
    stateEl.textContent = 'Saving…';
    stateEl.dataset.state = 'busy';
    const run = chain.then(task);
    chain = run.catch(() => {}).finally(() => {
        if (--pending === 0) {
            stateEl.replaceChildren(icon('check'), 'Saved');
            stateEl.dataset.state = 'ok';
        }
    });
    return run.catch(e => { toast(e.message, 'error'); stateEl.textContent = 'Not saved'; stateEl.dataset.state = 'error'; throw e; });
}

window.addEventListener('beforeunload', e => { if (pending) { e.preventDefault(); e.returnValue = ''; } });

/* ---------- floating bar ---------- */

const toggleBtn = el('button.ve-admin-btn.ve-admin-toggle', { type: 'button', 'aria-pressed': 'false', title: 'Show or hide the editor', onclick: () => setEditing(!editing) },
    el('span.ve-admin-switch'), el('span', {}, 'Edit'));
const addBtn = el('button.ve-admin-btn', { type: 'button', onclick: e => openAddMenu(e.currentTarget) }, icon('plus'), el('span', {}, 'Add'));
const bar = el('div.ve-admin-ui.ve-admin-bar', { role: 'toolbar', 'aria-label': 'Editor' },
    toggleBtn,
    el('div.ve-admin-bar-tools', {},
        addBtn,
        el('button.ve-admin-btn', { type: 'button', onclick: () => openTray() }, icon('image'), el('span', {}, 'Media')),
        el('button.ve-admin-btn', { type: 'button', onclick: () => openPagePanel(pageId) }, icon('page'), el('span', {}, 'Page')),
        stateEl,
    ),
    iconButton('logout', `Log out (${config.user})`, logout),
);

function logout() {
    const form = el('form', { method: 'post', action: config.logout, hidden: true }, el('input', { type: 'hidden', name: 'csrf', value: config.csrf }));
    document.body.append(form);
    form.submit();
}

/* ---------- edit mode ---------- */

function setEditing(on) {
    editing = on;
    html.classList.toggle('ve-admin-editing', on);
    toggleBtn.setAttribute('aria-pressed', String(on));
    store(STORE_KEY, on ? '1' : '0');
    if (on) {
        document.execCommand('defaultParagraphSeparator', false, 'p');
        enhance(main);
        reveal(main);
    } else {
        document.activeElement?.blur?.();
        select(null);
        closeMenu();
        teardown(main);
    }
}

/** Make text editable inside a scope (a newly inserted item or the whole page). */
function enhance(scope) {
    const items = scope.matches?.('[data-ve-id]') ? [scope, ...scope.querySelectorAll('[data-ve-id]')] : [...scope.querySelectorAll('[data-ve-id]')];
    for (const item of items) {
        const type = item.dataset.veType;
        if (type === 'heading') editable(item, 'text', true, 'Heading');
        if (type === 'text') editable(item, 'html', false, 'Write something…');
        if (type === 'image' || type === 'video') {
            let cap = item.querySelector(':scope > figcaption');
            if (!cap) {
                cap = el('figcaption.ve-admin-ghost');
                item.append(cap);
            }
            editable(cap, 'caption', true, 'Add a caption');
        }
    }
}

function editable(node, field, plain, placeholder) {
    node.contentEditable = plain && PLAIN ? 'plaintext-only' : 'true';
    node.dataset.veAdminField = field;
    node.dataset.veAdminPlaceholder = placeholder;
    node.spellcheck = true;
}

function teardown(scope) {
    scope.querySelectorAll('[data-ve-admin-field]').forEach(n => {
        n.removeAttribute('contenteditable');
        delete n.dataset.veAdminField;
        delete n.dataset.veAdminPlaceholder;
    });
    scope.querySelectorAll('figcaption.ve-admin-ghost').forEach(n => n.remove());
}

/** New or re-rendered items are not watched by site.js, so show their animated state at once. */
function reveal(scope) {
    [scope, ...scope.querySelectorAll('[class*="anim-"]')].forEach(n => { if (/\banim-/.test(n.className)) n.classList.add('is-visible'); });
}

/* ---------- inline text: save on blur ---------- */

const fieldOf = t => t?.closest?.('[data-ve-admin-field]');
const valueOf = f => (f.dataset.veAdminField === 'html' ? f.innerHTML : f.textContent.replace(/\s+/g, ' ').trim());

main.addEventListener('focusin', e => {
    const f = fieldOf(e.target);
    if (f) f.dataset.veAdminOrig = valueOf(f);
});

main.addEventListener('focusout', e => {
    const f = fieldOf(e.target);
    if (!f || f.contains(e.relatedTarget)) return;
    const value = valueOf(f);
    if (value === f.dataset.veAdminOrig) return;
    const item = f.closest('[data-ve-id]');
    const field = f.dataset.veAdminField;
    f.dataset.veAdminOrig = value;
    if (field === 'caption') f.classList.toggle('ve-admin-ghost', value === '');
    queue(async () => {
        const data = await api('item.update', { body: { id: Number(item.dataset.veId), content: { [field]: value } } });
        // Rich text comes back cleaned; show the stored version unless the owner is typing again.
        if (field === 'html' && document.activeElement !== f) {
            const clean = fromHTML(data.html);
            if (clean && clean.innerHTML !== f.innerHTML) f.innerHTML = clean.innerHTML;
        }
    }).catch(() => {});
});

main.addEventListener('keydown', e => {
    const f = fieldOf(e.target);
    if (!f) return;
    if (e.key === 'Escape') { f.blur(); e.stopPropagation(); return; }
    if (e.key === 'Enter' && f.dataset.veAdminField !== 'html') { e.preventDefault(); f.blur(); }
});

main.addEventListener('paste', e => {
    const f = fieldOf(e.target);
    if (!f) return;
    e.preventDefault();
    let text = e.clipboardData.getData('text/plain');
    if (f.dataset.veAdminField !== 'html') text = text.replace(/\s+/g, ' ');
    document.execCommand('insertText', false, text);
});

/* ---------- selection ---------- */

const itembar = el('div.ve-admin-ui.ve-admin-itembar', { role: 'toolbar', 'aria-label': 'Item', hidden: true });

main.addEventListener('click', e => {
    if (!editing) return;
    const link = e.target.closest('a');
    if (link && main.contains(link)) e.preventDefault(); // edit link text instead of following it
    select(e.target.closest('[data-ve-id]'));
});

main.addEventListener('dblclick', e => {
    if (!editing) return;
    const item = e.target.closest('[data-ve-type=image], [data-ve-type=video]');
    if (item && !e.target.closest('figcaption')) replaceMedia(item);
});

document.addEventListener('click', e => {
    if (editing && !e.target.closest('.ve-admin-ui, .ve-main, dialog')) select(null);
});

document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && editing && !fieldOf(e.target) && !document.querySelector('.ve-admin-drawer, .ve-admin-dialog[open]')) {
        if (menu) closeMenu(); else select(null);
    }
});

let hovered = null;
main.addEventListener('mouseover', e => {
    const item = editing ? e.target.closest('[data-ve-id]') : null;
    if (item === hovered) return;
    hovered?.removeAttribute('data-ve-admin-hover');
    hovered = item;
    hovered?.setAttribute('data-ve-admin-hover', '');
});
main.addEventListener('mouseleave', () => { hovered?.removeAttribute('data-ve-admin-hover'); hovered = null; });

function select(node) {
    if (selected && selected !== node) selected.removeAttribute('data-ve-admin-selected');
    selected = node && main.contains(node) ? node : null;
    selected?.setAttribute('data-ve-admin-selected', '');
    renderItembar();
}

function formatButton(text, title, run) {
    // mousedown keeps the text selection inside the editable field
    return el('button.ve-admin-btn.ve-admin-icon-btn.ve-admin-fmt', { type: 'button', title, 'aria-label': title, onmousedown: e => { e.preventDefault(); run(); } }, text);
}

function renderItembar() {
    closeMenu();
    if (!selected) { itembar.hidden = true; return; }
    const type = selected.dataset.veType;
    const parent = selected.parentElement.closest('[data-ve-id]');
    itembar.replaceChildren(...[
        el('span.ve-admin-itembar-label', {}, label(selected)),
        parent ? iconButton('up', `Select the ${label(parent)} around it`, () => select(parent)) : null,
        type === 'text' ? [
            el('span.ve-admin-sep'),
            formatButton(el('b', {}, 'B'), 'Bold', () => document.execCommand('bold')),
            formatButton(el('i', {}, 'I'), 'Italic', () => document.execCommand('italic')),
            formatButton(icon('link'), 'Link', addLink),
        ] : null,
        el('span.ve-admin-sep'),
        type === 'image' || type === 'video'
            ? el('button.ve-admin-btn', { type: 'button', onclick: () => replaceMedia(selected) }, icon(type), el('span', {}, 'Replace'))
            : null,
        type === 'image'
            ? el('button.ve-admin-btn', { type: 'button', title: 'Describe the image for screen readers and search engines', onclick: e => openAltEditor(e.currentTarget) }, 'Alt')
            : null,
        iconButton('plus', 'Add after this', e => openAddMenu(e.currentTarget)),
        iconButton('copy', 'Duplicate', duplicateSelected),
        iconButton('trash', 'Delete', deleteSelected, 've-admin-danger'),
    ].flat().filter(Boolean));
    itembar.hidden = false;
    positionItembar();
}

function positionItembar() {
    if (!selected || itembar.hidden) return;
    if (!selected.isConnected) { select(null); return; }
    const r = selected.getBoundingClientRect();
    const h = itembar.offsetHeight;
    const w = itembar.offsetWidth;
    let top = r.top - h - 6;
    if (top < 8) top = Math.max(8, Math.min(r.top + 6, innerHeight - h - 70));
    const left = Math.max(8, Math.min(r.left, innerWidth - w - 8));
    itembar.style.transform = `translate(${Math.round(left)}px, ${Math.round(top)}px)`;
}

let raf = 0;
const schedule = () => { cancelAnimationFrame(raf); raf = requestAnimationFrame(() => { positionItembar(); positionMenu(); }); };
addEventListener('scroll', schedule, { passive: true, capture: true });
addEventListener('resize', schedule, { passive: true });
new ResizeObserver(schedule).observe(main);

function addLink() {
    const sel = getSelection();
    if (!sel.rangeCount || sel.isCollapsed || !fieldOf(sel.anchorNode?.parentElement)) {
        toast('Select some text first, then add the link.');
        return;
    }
    const range = sel.getRangeAt(0);
    const url = prompt('Link address (leave empty to remove the link)', 'https://');
    sel.removeAllRanges();
    sel.addRange(range);
    if (url === null) return;
    if (url.trim() === '' || url.trim() === 'https://') document.execCommand('unlink');
    else document.execCommand('createLink', false, url.trim());
}

/* ---------- structure: add, duplicate, delete, replace media ---------- */

function insertPlaced({ html: markup, parent_id, after_id }) {
    const node = fromHTML(markup);
    const after = after_id ? find(after_id) : null;
    if (after) after.after(node);
    else (parent_id ? find(parent_id) : main)?.prepend(node);
    reveal(node);
    if (editing) enhance(node);
    select(node);
    node.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
}

function replaceItem(old, markup) {
    const node = fromHTML(markup);
    const wasSelected = selected === old;
    old.replaceWith(node);
    reveal(node);
    if (editing) enhance(node);
    if (wasSelected) select(node);
}

function addComponent(c) {
    const ref = selected ? Number(selected.dataset.veId) : null;
    queue(() => api('item.add', { body: { page_id: pageId, ref_id: ref, component: c.id } })).then(insertPlaced, () => {});
}

function duplicateSelected() {
    const id = Number(selected.dataset.veId);
    queue(() => api('item.duplicate', { body: { id } })).then(insertPlaced, () => {});
}

async function deleteSelected() {
    const node = selected;
    const hasChildren = CONTAINERS.includes(node.dataset.veType) && node.querySelector('[data-ve-id]');
    const ok = await confirmDialog({
        title: `Delete this ${label(node)}?`,
        text: (hasChildren ? 'Everything inside it is deleted too. ' : '') + 'This cannot be undone.',
    });
    if (!ok) return;
    queue(() => api('item.delete', { body: { id: Number(node.dataset.veId) } })).then(() => {
        if (selected === node) select(null);
        node.remove();
        toast(`Deleted the ${label(node)}`);
    }, () => {});
}

function replaceMedia(item) {
    const kind = item.dataset.veType;
    select(item);
    openTray({
        pick: kind,
        onPick: m => queue(() => api('item.update', { body: { id: Number(item.dataset.veId), content: { media_id: m.id } } }))
            .then(data => replaceItem(item, data.html), () => {}),
    });
}

/* ---------- add menu (component library) ---------- */

let menu = null;
let menuAnchor = null;

function openAddMenu(anchor) {
    if (menu && menuAnchor === anchor) { closeMenu(); return; }
    closeMenu();
    menuAnchor = anchor;
    const where = selected ? `Adds next to the selected ${label(selected)}` : 'Adds a section at the end of the page';
    menu = el('div.ve-admin-ui.ve-admin-menu', { role: 'menu' },
        el('p.ve-admin-menu-hint', {}, where),
        components.map(c => {
            const allowed = selected || c.type === 'section';
            return el('button.ve-admin-menu-item', {
                type: 'button', role: 'menuitem', disabled: !allowed,
                title: allowed ? null : 'Select an item on the page first',
                onclick: () => { closeMenu(); addComponent(c); },
            }, icon(c.type === 'video' ? 'video' : c.type === 'image' ? 'image' : 'plus'), c.label);
        }),
        selected ? null : el('p.ve-admin-menu-hint', {}, 'Select an item to add headings, text or media next to it.'),
    );
    document.body.append(menu);
    positionMenu();
    menu.querySelector('button:not([disabled])')?.focus();
}

/** Small popover to edit an image's alt text. Saves on Enter or when it closes. */
function openAltEditor(anchor) {
    if (menu && menuAnchor === anchor) { closeMenu(); return; }
    closeMenu();
    const item = selected;
    const current = item.querySelector('img')?.getAttribute('alt') ?? '';
    const input = el('input.ve-admin-alt-input', { type: 'text', value: current, maxlength: 255, placeholder: 'e.g. Red chair in a sunny studio', 'aria-label': 'Alt text' });
    const save = () => {
        const value = input.value.trim();
        if (value === current) return;
        queue(() => api('item.update', { body: { id: Number(item.dataset.veId), content: { alt: value } } }))
            .then(data => { if (item.isConnected) replaceItem(item, data.html); }, () => {});
    };
    menuAnchor = anchor;
    menu = el('form.ve-admin-ui.ve-admin-menu.ve-admin-alt', {
        onsubmit: e => { e.preventDefault(); closeMenu(); },
    },
        el('label.ve-admin-menu-hint', {}, 'Alt text: what the image shows, for screen readers and search engines. Leave empty to use the text saved with the file.'),
        input,
    );
    menu._onclose = save;
    input.addEventListener('keydown', e => {
        if (e.key !== 'Escape') return;
        e.stopPropagation();
        menu._onclose = null; // Escape cancels
        closeMenu();
    });
    document.body.append(menu);
    positionMenu();
    input.focus();
    input.select();
}

function positionMenu() {
    if (!menu || !menuAnchor) return;
    const r = menuAnchor.getBoundingClientRect();
    const h = menu.offsetHeight;
    const w = menu.offsetWidth;
    const below = r.bottom + 6 + h < innerHeight;
    const top = below ? r.bottom + 6 : Math.max(8, r.top - h - 6);
    const left = Math.max(8, Math.min(r.left, innerWidth - w - 8));
    menu.style.transform = `translate(${Math.round(left)}px, ${Math.round(top)}px)`;
}

function closeMenu() {
    const m = menu;
    menu = menuAnchor = null;
    m?.remove();
    m?._onclose?.();
}

document.addEventListener('pointerdown', e => {
    if (menu && !menu.contains(e.target) && !menuAnchor?.contains(e.target)) closeMenu();
});

/* ---------- start ---------- */

document.body.append(bar, itembar);
api('components').then(d => { components = d.components; }).catch(e => toast(e.message, 'error'));
setEditing(stored(STORE_KEY) !== '0');
