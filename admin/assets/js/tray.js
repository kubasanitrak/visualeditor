/* Media tray: upload (many at once), thumbnails, pick for an item, delete. */

import { api, el, icon, iconButton, toast, confirmDialog, drawer } from './util.js';

const ACCEPT = 'image/jpeg,image/png,image/gif,image/webp,image/avif,video/mp4,video/webm,video/quicktime';

/**
 * Open the tray.
 *   openTray()                                   browse, upload, delete
 *   openTray({ pick: 'image', onPick(media) })   choose one item of that kind
 */
export function openTray({ pick = null, onPick = null, title = null } = {}) {
    const d = drawer(title || (pick ? `Choose ${pick === 'video' ? 'a video' : 'an image'}` : 'Media'));
    const grid = el('div.ve-admin-tray-grid');
    const status = el('p.ve-admin-muted.ve-admin-tray-status');
    const input = el('input', { type: 'file', multiple: true, accept: pick === 'image' ? ACCEPT.split(',').filter(t => t.startsWith('image')).join(',') : pick === 'video' ? 'video/mp4,video/webm,video/quicktime' : ACCEPT, hidden: true });
    const uploadBtn = el('button.ve-admin-btn.ve-admin-btn-primary.ve-admin-tray-upload', { type: 'button', onclick: () => input.click() },
        icon('upload'), 'Upload');
    const drop = el('div.ve-admin-tray-drop', {}, uploadBtn, el('span.ve-admin-muted', {}, 'or drop files here'));
    d.body.append(drop, input, status, grid);

    let items = [];

    const render = () => {
        grid.replaceChildren();
        const list = pick ? items.filter(m => m.kind === pick) : items;
        if (!list.length) {
            grid.append(el('p.ve-admin-muted', {}, pick ? `No ${pick}s yet. Upload one above.` : 'No media yet. Upload images or videos above.'));
            return;
        }
        for (const m of list) {
            const preview = m.kind === 'video'
                ? el('video', { src: m.url + '#t=0.5', muted: true, preload: 'metadata', playsinline: true })
                : el('img', { src: m.thumb || m.url, alt: '', loading: 'lazy' });
            const tile = el('div.ve-admin-tile', { title: m.name },
                el('button.ve-admin-tile-main', {
                    type: 'button',
                    'aria-label': pick ? `Use ${m.name}` : m.name,
                    onclick: pick ? () => { d.close(); onPick?.(m); } : null,
                    tabindex: pick ? null : '-1',
                }, preview, m.kind === 'video' ? el('span.ve-admin-tile-badge', {}, icon('video')) : null),
                el('span.ve-admin-tile-name', {}, m.name),
            );
            if (!pick) {
                tile.append(iconButton('trash', `Delete ${m.name}`, async () => {
                    const ok = await confirmDialog({
                        title: `Delete ${m.name}?`,
                        text: (m.used ? `It is used in ${m.used} place${m.used > 1 ? 's' : ''} on the site, which will show an empty placeholder. ` : '')
                            + 'The file is removed for good.',
                    });
                    if (!ok) return;
                    try {
                        await api('media.delete', { body: { id: m.id } });
                        items = items.filter(x => x.id !== m.id);
                        render();
                        toast('Deleted');
                    } catch (e) { toast(e.message, 'error'); }
                }, 've-admin-tile-delete'));
            }
            grid.append(tile);
        }
    };

    const load = async () => {
        status.textContent = 'Loading…';
        try {
            const data = await api('media.list');
            items = data.media;
            status.textContent = `Images and videos up to ${data.max_mb} MB each.`;
            render();
        } catch (e) { status.textContent = e.message; }
    };

    const upload = async files => {
        files = [...files];
        if (!files.length) return;
        const form = new FormData();
        files.forEach(f => form.append('files[]', f));
        status.textContent = `Uploading ${files.length} file${files.length > 1 ? 's' : ''}…`;
        uploadBtn.disabled = true;
        try {
            const data = await api('media.upload', { form });
            items = [...data.media.reverse(), ...items];
            render();
            status.textContent = data.errors.length ? data.errors.join(' ') : `Uploaded ${data.media.length} file${data.media.length > 1 ? 's' : ''}.`;
            // Picking with a single fresh upload: use it straight away.
            if (pick && data.media.length === 1 && data.media[0].kind === pick) {
                d.close();
                onPick?.(data.media[0]);
            }
        } catch (e) {
            status.textContent = e.data?.errors?.join(' ') || e.message;
        } finally {
            uploadBtn.disabled = false;
            input.value = '';
        }
    };

    input.addEventListener('change', () => upload(input.files));
    d.box.addEventListener('dragover', e => { e.preventDefault(); d.box.classList.add('ve-admin-dragging'); });
    d.box.addEventListener('dragleave', e => { if (!d.box.contains(e.relatedTarget)) d.box.classList.remove('ve-admin-dragging'); });
    d.box.addEventListener('drop', e => {
        e.preventDefault();
        d.box.classList.remove('ve-admin-dragging');
        upload(e.dataTransfer.files);
    });

    load();
    return d;
}
