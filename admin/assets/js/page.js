/* Page panel: settings of the current page (each field saves on change), delete page,
   and the list of all pages with "New page". */

import { api, config, el, icon, toast, confirmDialog, drawer } from './util.js';
import { openTray } from './tray.js';

/** "Moje Práce" -> "moje-prace" (the server checks the result again). */
const slugify = s => s.normalize('NFD').replace(/[̀-ͯ]/g, '').trim().toLowerCase()
    .replace(/[^a-z0-9/]+/g, '-').replace(/^-+|-+$/g, '');

export async function openPagePanel(pageId) {
    const d = drawer('Page');
    d.body.append(el('p.ve-admin-muted', {}, 'Loading…'));

    let page, pages;
    let renderList = () => {};
    try {
        [page, pages] = await Promise.all([
            api('page.get', { params: { id: pageId } }).then(r => r.page),
            api('page.list').then(r => r.pages),
        ]);
    } catch (e) {
        d.body.replaceChildren(el('p.ve-admin-error', {}, e.message));
        return;
    }

    const save = async (patch, field) => {
        const err = field?.parentElement.querySelector('.ve-admin-field-error');
        if (err) err.textContent = '';
        try {
            const oldUrl = page.url;
            page = (await api('page.update', { body: { id: pageId, ...patch } })).page;
            if (page.url !== oldUrl) history.replaceState(null, '', page.url);
            if ('title' in patch) document.title = document.title.replace(/^.*?(?= – |$)/, page.title);
            renderList();
            toast('Saved');
            return true;
        } catch (e) {
            if (err) err.textContent = e.message; else toast(e.message, 'error');
            return false;
        }
    };

    const field = (label, control, hint) => el('label.ve-admin-field', {}, el('span', {}, label), control, hint ? el('small.ve-admin-muted', {}, hint) : null, el('small.ve-admin-field-error', { role: 'alert' }));

    /* ---------- this page ---------- */

    const title = el('input', { type: 'text', value: page.title, maxlength: 255, required: true });
    title.addEventListener('change', async () => { if (!await save({ title: title.value }, title)) title.value = page.title; });

    const base = location.origin + config.base;
    const slug = el('input', { type: 'text', value: page.slug, maxlength: 191, disabled: page.slug_locked, spellcheck: 'false', autocapitalize: 'off' });
    slug.addEventListener('change', async () => {
        slug.value = slugify(slug.value);
        if (!await save({ slug: slug.value }, slug)) slug.value = page.slug;
    });

    const desc = el('textarea', { rows: 3, maxlength: 500 }, page.meta_description);
    desc.addEventListener('change', () => save({ meta_description: desc.value }, desc));

    const ogBox = el('div.ve-admin-og');
    const renderOg = () => {
        ogBox.replaceChildren(
            page.og ? el('img', { src: page.og.thumb || page.og.url, alt: '' }) : el('div.ve-admin-og-empty.ve-admin-muted', {}, 'No image'),
            el('div.ve-admin-og-actions', {},
                el('button.ve-admin-btn', {
                    type: 'button',
                    onclick: () => openTray({
                        pick: 'image', title: 'Choose a share image',
                        onPick: async m => { await save({ og_image_id: m.id }); openPagePanel(pageId); },
                    }),
                }, page.og ? 'Change' : 'Choose image'),
                page.og ? el('button.ve-admin-btn', { type: 'button', onclick: async () => { if (await save({ og_image_id: null })) renderOg(); } }, 'Remove') : null,
            ),
        );
    };
    renderOg();

    const deleteBtn = page.slug_locked ? null : el('button.ve-admin-btn.ve-admin-btn-quiet-danger', {
        type: 'button',
        onclick: async () => {
            const ok = await confirmDialog({
                title: `Delete the page “${page.title}”?`,
                text: 'Everything on it is deleted too, and its address stops working. This cannot be undone.',
                confirm: 'Delete page',
            });
            if (!ok) return;
            try {
                const r = await api('page.delete', { body: { id: pageId } });
                location.href = r.home;
            } catch (e) { toast(e.message, 'error'); }
        },
    }, icon('trash'), 'Delete this page');

    /* ---------- all pages ---------- */

    const list = el('ul.ve-admin-pages');
    renderList = () => list.replaceChildren(...pages.map(p => {
        if (p.id === pageId) Object.assign(p, { title: page.title, slug: page.slug, url: page.url });
        return el('li', {},
            el('a', { href: p.url, 'aria-current': p.id === pageId ? 'page' : null },
                el('span', {}, p.title || p.slug),
                el('small.ve-admin-muted', {}, p.slug === 'home' ? '/' : '/' + p.slug)));
    }));
    renderList();

    const newTitle = el('input', { type: 'text', placeholder: 'Title of the new page', maxlength: 255, 'aria-label': 'Title of the new page' });
    const newErr = el('small.ve-admin-field-error', { role: 'alert' });
    const create = async e => {
        e.preventDefault();
        newErr.textContent = '';
        if (!newTitle.value.trim()) { newTitle.focus(); return; }
        try {
            const r = await api('page.create', { body: { title: newTitle.value } });
            location.href = r.page.url;
        } catch (err) { newErr.textContent = err.message; }
    };
    const newForm = el('form.ve-admin-new-page', { onsubmit: create },
        el('div.ve-admin-row', {}, newTitle, el('button.ve-admin-btn.ve-admin-btn-primary', { type: 'submit' }, icon('plus'), 'New page')),
        newErr);

    d.body.replaceChildren(...[
        field('Title', title, 'Shown in the browser tab and when the page is shared.'),
        field('Address', slug, page.slug_locked ? 'The address of this page is fixed.' : `${base}/${page.slug}`),
        field('Description', desc, 'Short summary for search engines and link previews.'),
        el('div.ve-admin-field', {}, el('span', {}, 'Social share image'), ogBox, el('small.ve-admin-muted', {}, 'Used when the page is shared. About 1200 × 630 works best.')),
        deleteBtn,
        el('hr.ve-admin-rule'),
        el('div.ve-admin-field', {}, el('span', {}, 'All pages'), list, newForm),
    ].filter(Boolean));
    slug.addEventListener('input', () => {
        const hint = slug.parentElement.querySelector('small.ve-admin-muted');
        if (hint) hint.textContent = `${base}/${slugify(slug.value)}`;
    });
}
