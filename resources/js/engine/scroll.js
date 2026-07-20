/* =========================================================================
   ZeroBook — viewport-aware scroll helper.
   Ensures a target element is visible when keyboard navigation moves focus or
   highlights it inside a scrollable container, not just the browser window.
   ========================================================================= */

function getNearestScrollContainer(el) {
    if (!el || typeof window === 'undefined') return null;

    let node = el.parentElement;
    while (node) {
        const style = window.getComputedStyle(node);
        const overflowY = style.overflowY || style.overflow;
        const overflowX = style.overflowX || style.overflow;
        const canScrollY = /(auto|scroll|overlay)/.test(overflowY) && node.scrollHeight > node.clientHeight;
        const canScrollX = /(auto|scroll|overlay)/.test(overflowX) && node.scrollWidth > node.clientWidth;
        if (canScrollY || canScrollX) return node;
        node = node.parentElement;
    }

    return null;
}

export function ensureElementVisible(el, options = {}) {
    if (!el || typeof el.getBoundingClientRect !== 'function') return false;

    const block = options.block || 'nearest';
    const inline = options.inline || 'nearest';
    const container = options.container || getNearestScrollContainer(el);

    if (container && container !== window && container !== document) {
        const rect = el.getBoundingClientRect();
        const containerRect = container.getBoundingClientRect();
        const top = rect.top - containerRect.top + container.scrollTop;
        const bottom = rect.bottom - containerRect.top + container.scrollTop;
        const left = rect.left - containerRect.left + container.scrollLeft;
        const right = rect.right - containerRect.left + container.scrollLeft;

        const deltaY = top < container.scrollTop
            ? top - container.scrollTop
            : bottom > container.scrollTop + container.clientHeight
                ? bottom - (container.scrollTop + container.clientHeight)
                : 0;
        const deltaX = left < container.scrollLeft
            ? left - container.scrollLeft
            : right > container.scrollLeft + container.clientWidth
                ? right - (container.scrollLeft + container.clientWidth)
                : 0;

        if (deltaY || deltaX) {
            container.scrollTo({
                left: container.scrollLeft + deltaX,
                top: container.scrollTop + deltaY,
                behavior: 'auto',
            });
        }
        return true;
    }

    if (typeof el.scrollIntoView === 'function') {
        el.scrollIntoView({ block, inline });
        return true;
    }

    if (typeof window !== 'undefined' && typeof window.scrollTo === 'function') {
        const rect = el.getBoundingClientRect();
        const viewportHeight = window.innerHeight || document.documentElement.clientHeight || 0;
        const deltaY = rect.top < 0 ? rect.top : rect.bottom > viewportHeight ? rect.bottom - viewportHeight : 0;
        if (deltaY) {
            window.scrollTo({ top: window.scrollY + deltaY, behavior: 'auto' });
        }
        return true;
    }

    return false;
}
