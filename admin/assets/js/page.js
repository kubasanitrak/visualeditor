/* Page settings panel: title, slug, description, social share image. Each field saves on change. */

import { api, config, el, toast, drawer } from './util.js';
import { openTray } from './tray.js';

export async function openPagePanel(pageId) {
    const d = drawer('Page');
    d.body.append(el('p.ve-admin-muted', {}, 'Loading…'));

    let page;
    try {
        page = (await api('page.get', { params: { id: pageId } })).page;
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
            toast('Saved');
            return true;
        } catch (e) {
            if (err) err.textContent = e.message; else toast(e.message, 'error');
            return false;
        }
    };

    const field = (label, control, hint) => el('label.ve-admin-field', {}, el('span', {}, label), control, hint ? el('small.ve-admin-muted', {}, hint) : null, el('small.ve-admin-field-error', { role: 'alert' }));

    const title = el('input', { type: 'text', value: page.title, maxlength: 255, required: true });
    title.addEventListener('change', async () => { if (!await save({ title: title.value }, title)) title.value = page.title; });

    const base = location.origin + config.base;
    const slug = el('input', { type: 'text', value: page.slug, maxlength: 191, disabled: page.slug_locked, spellcheck: 'false', autocapitalize: 'off' });
    slug.addEventListener('change', async () => {
        slug.value = slug.value.trim().toLowerCase().replace(/\s+/g, '-');
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

    d.body.replaceChildren(
        field('Title', title, 'Shown in the browser tab and when the page is shared.'),
        field('Address', slug, page.slug_locked ? 'The address of this page is fixed.' : `${base}/${page.slug}`),
        field('Description', desc, 'Short summary for search engines and link previews.'),
        el('div.ve-admin-field', {}, el('span', {}, 'Social share image'), ogBox, el('small.ve-admin-muted', {}, 'Used when the page is shared. About 1200 × 630 works best.')),
    );
    slug.addEventListener('input', () => {
        const hint = slug.parentElement.querySelector('small.ve-admin-muted');
        if (hint) hint.textContent = `${base}/${slug.value.trim().toLowerCase().replace(/\s+/g, '-')}`;
    });
}
