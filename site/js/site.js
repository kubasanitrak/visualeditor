/* Site runtime: scroll-triggered animations. No dependencies, no build step. */
(() => {
    const selector = '.anim-fade-in, .anim-slide-up, .anim-slide-left, .anim-slide-right, .anim-zoom-in';
    const items = document.querySelectorAll(selector);
    if (!items.length) return;

    if (!('IntersectionObserver' in window)) {
        items.forEach(el => el.classList.add('is-visible'));
        return;
    }

    const io = new IntersectionObserver(entries => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add('is-visible');
                io.unobserve(entry.target);
            }
        });
    }, { rootMargin: '0px 0px -10% 0px', threshold: 0.1 });

    items.forEach(el => io.observe(el));
})();
