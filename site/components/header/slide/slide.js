// header/slide: hide the header when scrolling down, show it when
// scrolling up. Never hides while the menu is open or focus is inside it.
export default function initSlide(root = document) {
	// Checks the class on every scroll, so switching variants needs no re-init.
	const header = root.querySelector('.header');
	if (!header) return;
	let last = window.scrollY;
	let ticking = false;

	const update = () => {
		ticking = false;
		const y = window.scrollY;
		if (!header.classList.contains('header--slide')) {
			header.classList.remove('is-hidden');
			last = y;
			return;
		}
		const open = header.querySelector('.nav-switch:checked') || header.querySelector(':focus-visible');
		const pastHeader = y > header.offsetHeight;
		if (open || !pastHeader || y < last) header.classList.remove('is-hidden');
		else if (y > last + 4) header.classList.add('is-hidden');
		last = y;
	};

	window.addEventListener('scroll', () => {
		if (!ticking) {
			ticking = true;
			requestAnimationFrame(update);
		}
	}, { passive: true });
}
