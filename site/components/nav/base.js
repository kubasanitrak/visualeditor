// Navigation: small layer on top of the checkbox hack. Without JS the menu
// still opens and closes; this adds Escape to close (focus goes back to the
// switch), closing after a link click and closing on a click outside.
export default function initNav(root = document) {
	const navs = [...root.querySelectorAll('.nav')].filter((nav) => nav.querySelector('.nav-switch'));
	if (!navs.length) return;

	const close = (nav, focus) => {
		const sw = nav.querySelector('.nav-switch');
		if (!sw.checked) return;
		sw.checked = false;
		sw.dispatchEvent(new Event('change', { bubbles: true }));
		if (focus) sw.focus({ preventScroll: true });
	};

	document.addEventListener('keydown', (e) => {
		if (e.key !== 'Escape') return;
		navs.forEach((nav) => close(nav, true));
	});

	document.addEventListener('click', (e) => {
		navs.forEach((nav) => {
			if (e.target.closest('.menu a') && nav.contains(e.target)) close(nav, false);
			else if (!nav.contains(e.target)) close(nav, false);
		});
	});
}
