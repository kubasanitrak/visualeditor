// Header: keeps --header-h (on the header and on :root) equal to the
// header's height, for the overlay option, the slide variant and any page
// content that needs to clear a sticky header.
export default function initHeader(root = document) {
	const header = root.querySelector('.header');
	if (!header) return;
	const set = () => {
		const h = `${header.offsetHeight}px`;
		header.style.setProperty('--header-h', h);
		document.documentElement.style.setProperty('--header-h', h);
	};
	set();
	if ('ResizeObserver' in window) new ResizeObserver(set).observe(header);
	else window.addEventListener('resize', set);
}
