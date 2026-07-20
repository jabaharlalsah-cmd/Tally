/* =========================================================================
   ZeroBook keyboard engine — key normalisation + browser-reserved key policy
   Pure functions, no Alpine dependency. Everything is client-side.
   ========================================================================= */

// Map DOM KeyboardEvent.key values to our canonical token.
const NAMED = {
    ' ': 'space',
    Spacebar: 'space',
    Escape: 'escape',
    Esc: 'escape',
    Enter: 'enter',
    Tab: 'tab',
    Backspace: 'backspace',
    Delete: 'delete',
    ArrowUp: 'arrowup',
    ArrowDown: 'arrowdown',
    ArrowLeft: 'arrowleft',
    ArrowRight: 'arrowright',
    Home: 'home',
    End: 'end',
    PageUp: 'pageup',
    PageDown: 'pagedown',
};

/**
 * Build a canonical combo string from a keydown event.
 * Order is fixed: ctrl+alt+shift+meta+<key>. Letters are lower-cased so that
 * Shift+A and A both resolve their letter to "a" (shift is a separate token).
 */
export function combo(e) {
    let key = e.key;
    // macOS composes Option+<letter> into a glyph — Option+C arrives as "ç",
    // Option+G as "©", Option+I as a dead "ˆ" — so e.key cannot say which letter
    // was pressed and every Alt+ shortcut (Alt+C create, Alt+G Go To, Alt+P print…)
    // resolved to nothing on a Mac. Fall back to e.code, which names the physical
    // key, ONLY when the composed e.key is no longer a plain letter/digit. Keeping
    // e.key when it is usable matters on non-US layouts: e.code is positional, so
    // on AZERTY it would read the key labelled A as "q". Windows/Linux never
    // compose, so they keep their existing behaviour on every layout.
    if (e.altKey && typeof e.code === 'string' && !/^[a-z0-9]$/i.test(key)) {
        const physical = /^(?:Key([A-Z])|Digit([0-9]))$/.exec(e.code);
        if (physical) key = (physical[1] || physical[2]).toLowerCase();
    }
    if (NAMED[key]) key = NAMED[key];
    else if (/^F\d{1,2}$/.test(key)) key = key.toLowerCase(); // F1..F12
    else if (key.length === 1) key = key.toLowerCase();
    else key = key.toLowerCase();

    const parts = [];
    if (e.ctrlKey) parts.push('ctrl');
    if (e.altKey) parts.push('alt');
    // Only surface shift as a modifier for non-printable keys; for a printable
    // character shift is already folded into casing above, and we lower-case it.
    if (e.shiftKey && (key.length > 1 || /^f\d{1,2}$/.test(key))) parts.push('shift');
    if (e.metaKey) parts.push('meta');
    parts.push(key);
    return parts.join('+');
}

/** A "bare character" combo is a single printable char with no modifiers. */
export function isBareChar(c) {
    return /^[a-z0-9]$/.test(c);
}

/** Is the event target something the user is typing into? */
export function isEditable(el) {
    if (!el) return false;
    const tag = el.tagName;
    if (tag === 'TEXTAREA' || tag === 'SELECT') return true;
    if (tag === 'INPUT') {
        const t = (el.getAttribute('type') || 'text').toLowerCase();
        return !['button', 'submit', 'reset', 'checkbox', 'radio', 'range', 'file'].includes(t);
    }
    if (el.isContentEditable) return true;
    return false;
}

/**
 * Browser-reserved keys we ALWAYS try to neutralise (preventDefault) so the
 * browser's own behaviour never fires while inside ZeroBook. These are
 * reliably preventable in a normal Chrome tab.
 */
export const HARD_BLOCK = new Set([
    'f1', // Chrome help
    'f2',
    'f3', // Find
    'f4',
    'f5', // Reload
    'ctrl+f5', // Hard reload
    'f6', // Address bar (best-effort; not always capturable)
    'f7', // Caret browsing prompt
    'f8', // Sales voucher (only a DevTools resume key when DevTools is focused)
    'f9', // Purchase voucher
    'f11', // Fullscreen (best-effort; browser usually wins — Alt+F11 is the reliable alternate)
    'f12', // DevTools (best-effort; browser usually wins — Alt+F12 is the reliable alternate)
    'ctrl+s', // Save page
    'ctrl+p', // Print dialog (we own printing later)
    'alt+g',
    'ctrl+n', // We repurpose for calculator; may be browser-reserved (see fallbacks)
]);

/**
 * Keys the browser reserves and a normal tab CANNOT reliably capture.
 * We do not fight these; instead the engine provides working alternates.
 * This table drives PHASE1_README's documented-fallbacks section.
 *
 * Phase 7C — inside the desktop (Tauri) edition there is no browser chrome, so
 * every one of these IS reliably capturable and its raw key works. The engine
 * already binds the raw keys (f6→Receipt, f11→Features, f12→Config) alongside
 * their Alt+ alternates; on the desktop, preventDefault finally wins the race, so
 * those raw keys just work. See {@link uncapturableKeys} and {@link shouldHardBlock}.
 */
export const UNCAPTURABLE = [
    { key: 'F11', purpose: 'Full screen', alternate: 'Alt+F (documented)', reliable: false },
    { key: 'F12', purpose: 'DevTools', alternate: 'None needed by app', reliable: false },
    { key: 'Ctrl+N', purpose: 'New window (browser)', alternate: 'Alt+N (calculator)', reliable: false },
    { key: 'F6', purpose: 'Focus address bar', alternate: 'Alt+G (Go To)', reliable: false },
    { key: 'Ctrl+W / Ctrl+T', purpose: 'Close / new tab', alternate: 'Not used by app', reliable: false },
];

/**
 * Phase 7C — is the engine running inside the ZeroBook Desktop (Tauri) shell?
 * The shell sets window.ZB_DESKTOP = true (injected before the app boots) and the
 * Tauri global is present; either is sufficient.
 */
export function isDesktopRuntime() {
    return typeof window !== 'undefined' && (window.ZB_DESKTOP === true || !!window.__TAURI__ || !!window.__TAURI_INTERNALS__);
}

/**
 * Keys that a browser tab cannot capture but a desktop window CAN, so the desktop
 * must neutralise them too. F3/F6/F11/F12 are already in HARD_BLOCK (best-effort in
 * a browser, reliable in the desktop); Ctrl+W / Ctrl+T are deliberately NOT blocked
 * in a browser (fighting them there is futile and breaks the user's tab), so they
 * are added ONLY on the desktop — where they would otherwise close/replace the app
 * window. The app assigns them no action; they are simply captured and ignored.
 */
export const DESKTOP_ONLY_BLOCK = new Set(['ctrl+w', 'ctrl+t']);

/** Should this combo be neutralised (preventDefault)? Desktop-aware. */
export function shouldHardBlock(c) {
    return HARD_BLOCK.has(c) || (isDesktopRuntime() && DESKTOP_ONLY_BLOCK.has(c));
}

/**
 * The keys the current runtime genuinely can't capture. In the desktop edition this
 * is empty — the browser-reserved gap is closed. In a browser tab it's the list
 * above (drives the harness / README fallbacks display).
 */
export function uncapturableKeys() {
    return isDesktopRuntime() ? [] : UNCAPTURABLE;
}
