/* =========================================================================
   ZeroBook keyboard engine — key normalisation + browser-reserved key policy
   Pure functions, no Alpine dependency. Everything is client-side.

   Source of truth for the shortcut scheme:
     C:\laragon\www\New Account Software\_docs\keyboard-shortcuts.md
   That document is authoritative (TallyPrime 7.x). Where it and any other
   note disagree, the document wins. Do not invent bindings here.
   ========================================================================= */

/* ---- platform detection (resolved once) --------------------------------- */

/**
 * One-shot platform probe. Only used to decide modifier policy, so a
 * lightweight heuristic is enough; misdetection degrades to Windows
 * behaviour rather than breaking outright.
 */
let _platform = null;
export function platform() {
    if (_platform) return _platform;
    const nav = typeof navigator !== 'undefined' ? navigator : {};
    const uaData = nav.userAgentData || null;
    const plat = String((uaData && uaData.platform) || nav.platform || '').toLowerCase();
    const ua = String(nav.userAgent || '');
    const isMac = plat.includes('mac');

    let browser = 'other';
    const brands = ((uaData && uaData.brands) || []).map((b) => String(b.brand).toLowerCase());
    if (brands.some((b) => b.includes('chromium') || b.includes('google chrome') || b.includes('microsoft edge'))) {
        browser = 'chromium';
    } else if (/firefox/i.test(ua)) {
        browser = 'firefox';
    } else if (/safari/i.test(ua) && !/chrome|chromium|crios|edg/i.test(ua)) {
        browser = 'safari';
    } else if (/chrome|crios|edg/i.test(ua)) {
        browser = 'chromium';
    }

    const mm = typeof window !== 'undefined' && window.matchMedia ? window.matchMedia.bind(window) : null;
    _platform = {
        isMac,
        isWindows: !isMac && /win/i.test(plat),
        browser,
        isStandalone:
            (!!mm &&
                (mm('(display-mode: standalone)').matches ||
                    mm('(display-mode: window-controls-overlay)').matches)) ||
            nav.standalone === true,
    };
    return _platform;
}

/** Test hook — clears the memoised platform probe. */
export function __resetPlatformCache() {
    _platform = null;
}

/**
 * Combos macOS reserves for the OS or the browser shell. A tab cannot take
 * these, and trying to swallow them makes the app feel broken (⌘Q appearing
 * to hang, ⌘C not copying). We pass them straight through, untouched.
 * Keyed by e.code so keyboard layout is irrelevant. Doc §G.
 */
export const MAC_CMD_RESERVED_CODES = new Set([
    'KeyQ', // quit application
    'KeyW', // close window
    'KeyH', // hide application
    'KeyM', // minimise
    'KeyN', // new window
    'KeyT', // new tab
    // ⌘R is the browser's reload and doc §G leaves it to macOS. It MUST be
    // listed here: without it, folding Cmd into Ctrl turns ⌘R into "ctrl+r",
    // which HARD_BLOCK claims — silently breaking reload for every Mac user.
    // A real Ctrl+R still carries no metaKey, so it is unaffected and stays
    // guarded by RELOAD_GUARD.
    'KeyR',
    'Space', // Spotlight
    'Comma', // application settings
    'Backquote', // cycle windows
    'BracketLeft', // history back (Safari web apps)
    'BracketRight', // history forward
    'Tab', // application switcher
]);

/**
 * Editing combos that must stay native while focus is in a field, so that
 * copy/paste/undo keep working. Ctrl+A is the deliberate exception — the
 * shortcut doc §A says to own it in-field as Tally's Accept.
 */
const NATIVE_EDIT_CODES = new Set(['KeyC', 'KeyV', 'KeyX', 'KeyZ', 'KeyY']);

/* ---- key naming --------------------------------------------------------- */

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

// Map KeyboardEvent.code to our canonical token, for the paths where the
// physical key is what matters (see `combo`).
const CODE_NAMED = {
    Escape: 'escape',
    Enter: 'enter',
    NumpadEnter: 'enter',
    Tab: 'tab',
    Space: 'space',
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

/** KeyboardEvent.code → canonical token, or null when it isn't a key we name. */
export function tokenFromCode(code) {
    if (!code || typeof code !== 'string') return null;
    if (CODE_NAMED[code]) return CODE_NAMED[code];
    let m = /^Key([A-Z])$/.exec(code);
    if (m) return m[1].toLowerCase();
    m = /^(?:Digit|Numpad)([0-9])$/.exec(code);
    if (m) return m[1];
    if (/^F\d{1,2}$/.test(code)) return code.toLowerCase();
    return null;
}

/**
 * Build a canonical combo string from a keydown event.
 * Order is fixed: ctrl+alt+shift+<key>.
 *
 * KEY RESOLUTION IS DELIBERATELY HYBRID, not pure e.code:
 *
 *   - For Alt/Option combos we MUST use e.code. macOS composes Option+<letter>
 *     into a glyph — Option+C arrives as "ç", Option+G as "©", Option+I as a
 *     dead "ˆ" — so e.key cannot say which letter was pressed, and every Alt+
 *     shortcut (Alt+C create, Alt+G Go To, Alt+P print…) resolved to nothing
 *     on a Mac.
 *   - Everywhere else we prefer e.key, because e.code is POSITIONAL. On AZERTY
 *     the key labelled A sits where QWERTY has Q, so a pure-code matcher would
 *     fire Ctrl+A when the user pressed the key labelled Q. e.key respects the
 *     user's own layout, and does not compose for Ctrl combos on any platform.
 *
 * This satisfies the stated intent of the shortcut doc — Alt/Option combos must
 * resolve to the intended letter on Mac keyboards — without breaking non-US
 * layouts, which a pure-code matcher would.
 *
 * MODIFIER POLICY: on macOS the Command key is folded into `ctrl`, so a Mac
 * user pressing ⌘A matches the very same "ctrl+a" binding a Windows user
 * reaches with Ctrl+A. Screens therefore register Ctrl combos once and get
 * both platforms. Combos macOS reserves are filtered out earlier, by
 * {@link shouldIgnore}, and never reach here.
 */
export function combo(e) {
    const p = platform();
    let key = null;

    // Alt/Option held → trust the physical key; e.key is unreliable (see above).
    if (e.altKey) {
        key = tokenFromCode(e.code);
    }

    if (key === null || key === undefined) {
        key = e.key;
        if (typeof key !== 'string' || key === '') {
            key = tokenFromCode(e.code) || '';
        } else if (NAMED[key]) {
            key = NAMED[key];
        } else {
            key = key.toLowerCase(); // covers F1..F12 and single characters alike
        }
    }

    if (!key) return '';

    const parts = [];
    // Cmd on macOS is Tally's Ctrl. Elsewhere Meta is the OS key and never ours.
    if (e.ctrlKey || (p.isMac && e.metaKey)) parts.push('ctrl');
    if (e.altKey) parts.push('alt');
    // Only surface shift as a modifier for non-printable keys; for a printable
    // character shift is already folded into casing above, and we lower-case it.
    if (e.shiftKey && (key.length > 1 || /^f\d{1,2}$/.test(key))) parts.push('shift');
    parts.push(key);
    return parts.join('+');
}

/**
 * Should this keydown be ignored entirely — left to the OS, the browser, or an
 * input method — before we even look for a binding?
 *
 * Returns a reason string (truthy) to ignore, or null to carry on.
 */
export function shouldIgnore(e) {
    // 1. IME composition and dead keys. Indic, CJK and European accent input all
    //    route through here; stealing these keystrokes breaks ordinary typing.
    if (e.isComposing || e.keyCode === 229 || e.key === 'Dead') return 'ime';

    // 2. AltGr layouts report Ctrl+Alt for ordinary characters (@ € ~ on many
    //    European layouts). Those keystrokes are typing, not commands.
    if (typeof e.getModifierState === 'function' && e.getModifierState('AltGraph')) return 'altgr';

    const p = platform();

    // 3. macOS combos the OS or browser shell owns — pass through untouched.
    if (p.isMac && e.metaKey && MAC_CMD_RESERVED_CODES.has(e.code)) return 'mac-reserved';

    // 4. Windows/Linux: a held Windows/Super key means the OS may act.
    if (!p.isMac && e.metaKey) return 'os-meta';

    // 5. Native editing combos inside a field always stay native. On macOS this
    //    is what keeps ⌘C/⌘V/⌘X/⌘Z copying and pasting rather than being eaten
    //    by a Ctrl-folded binding.
    if (
        (e.ctrlKey || (p.isMac && e.metaKey)) &&
        !e.altKey &&
        NATIVE_EDIT_CODES.has(e.code) &&
        isEditable(e.target)
    ) {
        return 'native-edit';
    }

    return null;
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
 * Navigation keys may auto-repeat when held; action keys may not. Holding F5
 * must not open twenty Payment vouchers, but holding ↓ must still scroll a
 * report.
 */
const REPEATABLE = new Set([
    'arrowup',
    'arrowdown',
    'arrowleft',
    'arrowright',
    'pageup',
    'pagedown',
    'home',
    'end',
    'backspace',
    'delete',
]);
export function mayRepeat(c) {
    if (typeof c !== 'string' || c === '') return false;
    return REPEATABLE.has(c.split('+').pop());
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
    'ctrl+r', // Reload — see RELOAD_GUARD; listed here so it is claimed even unbound
    'ctrl+s', // Save page
    'ctrl+p', // Print dialog (we own printing later)
    'alt+g',
    'ctrl+n', // We repurpose for calculator; may be browser-reserved (see fallbacks)
]);

/**
 * Reload combos that must NEVER reach the browser, whether or not a binding
 * exists for them (doc §F.3). F5 is Tally's Payment key — the single most
 * pressed voucher key — and Ctrl+R sits right under the fingers during data
 * entry. An accidental reload silently discards an in-progress voucher, so
 * both are claimed unconditionally, with the dirty-state guard below as the
 * backstop for the paths a page cannot intercept.
 *
 * ⌘R on macOS is deliberately NOT claimed: doc §G leaves Cmd to the OS. The
 * beforeunload guard covers that path instead.
 */
export const RELOAD_GUARD = [
    { key: 'f5', anyModifiers: true },
    { key: 'r', ctrl: true, macCmdExempt: true },
];

/** Does this event need to be swallowed purely to stop a page reload? */
export function isReloadCombo(e) {
    const p = platform();
    const token = tokenFromCode(e.code) || String(e.key || '').toLowerCase();
    for (const g of RELOAD_GUARD) {
        if (g.key !== token) continue;
        // ⌘R belongs to macOS; only a real Ctrl press is ours there.
        if (g.macCmdExempt && p.isMac && e.metaKey && !e.ctrlKey) return false;
        if (g.anyModifiers) return true;
        if (g.ctrl && !e.ctrlKey) continue;
        return true;
    }
    return false;
}

/* ---- unsaved-work guard ------------------------------------------------- */

/**
 * Screens holding unsaved input register a probe here. The browser consults it
 * before unloading the page, so a stray reload, tab close or ⌘R cannot silently
 * discard a half-entered voucher. Section 3 of the build brief requires this
 * guard, and doc §F.3 names it as the backstop for the reload keys.
 *
 * Returns an unregister function, so a screen can drop its probe on teardown.
 */
const dirtyProbes = new Map();
let unloadBound = false;

function bindUnloadGuard() {
    if (unloadBound || typeof window === 'undefined') return;
    window.addEventListener('beforeunload', (ev) => {
        if (!isDirty()) return;
        ev.preventDefault();
        // Legacy browsers need returnValue set; the string itself is ignored.
        ev.returnValue = '';
        return '';
    });
    unloadBound = true;
}

export function registerDirty(name, probe) {
    if (typeof probe !== 'function') return () => {};
    dirtyProbes.set(name, probe);
    bindUnloadGuard();
    return () => dirtyProbes.delete(name);
}

export function clearDirty(name) {
    dirtyProbes.delete(name);
}

export function isDirty() {
    for (const probe of dirtyProbes.values()) {
        try {
            if (probe()) return true;
        } catch (err) {
            console.error('[ZB] dirty probe failed', err);
        }
    }
    return false;
}

/**
 * Drop every probe, disarming the unload guard.
 *
 * Called immediately before a navigation the user has already confirmed —
 * otherwise beforeunload fires a second, browser-native prompt on top of the
 * one they just answered, which reads as the app refusing to let them leave.
 */
export function clearAllDirty() {
    dirtyProbes.clear();
}

/** Test hook — same thing, named for intent at the call site. */
export function __resetDirty() {
    clearAllDirty();
}

/* ---- runtime / reserved-key reporting ----------------------------------- */

/**
 * Keys the browser reserves and a normal tab CANNOT reliably capture.
 * We do not fight these; instead the engine provides working alternates.
 *
 * Recovery paths, in descending order of fidelity:
 *   1. ZeroBook Desktop (Tauri) — no browser chrome, every key is ours.
 *   2. Installed PWA (standalone display-mode) — removes the address bar and
 *      tab strip, so F11/F12/Ctrl+T/Alt+D stop being browser keys.
 *   3. Plain browser tab — the alternates below, plus the right-side button bar.
 */
export const UNCAPTURABLE = [
    { key: 'F11', purpose: 'Full screen', alternate: 'Alt+F11, or the Features button', reliable: false },
    { key: 'F12', purpose: 'DevTools', alternate: 'Alt+F12, or the Configure button', reliable: false },
    { key: 'Ctrl+N', purpose: 'New window (browser)', alternate: 'Ctrl+Alt+N, or the calculator button', reliable: false },
    { key: 'F6', purpose: 'Focus address bar', alternate: 'Alt+G (Go To) → Receipt', reliable: false },
    { key: 'Ctrl+W / Ctrl+T', purpose: 'Close / new tab', alternate: 'Not used by app', reliable: false },
];

/**
 * Is the engine running inside the ZeroBook Desktop (Tauri) shell?
 * The shell sets window.ZB_DESKTOP = true (injected before the app boots) and the
 * Tauri global is present; either is sufficient.
 */
export function isDesktopRuntime() {
    return (
        typeof window !== 'undefined' &&
        (window.ZB_DESKTOP === true || !!window.__TAURI__ || !!window.__TAURI_INTERNALS__)
    );
}

/** Is the engine running as an installed PWA rather than in a browser tab? */
export function isStandaloneRuntime() {
    return platform().isStandalone;
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
 * The keys the current runtime genuinely can't capture. In the desktop edition
 * this is empty — the browser-reserved gap is closed. Installed as a PWA only
 * Ctrl+N survives (it opens a browser window even in standalone mode). In a
 * plain browser tab it is the whole list, which drives the harness and the
 * on-screen help.
 */
export function uncapturableKeys() {
    if (isDesktopRuntime()) return [];
    if (isStandaloneRuntime()) return UNCAPTURABLE.filter((u) => u.key === 'Ctrl+N');
    return UNCAPTURABLE;
}

/* ---- on-screen hint rendering ------------------------------------------- */

const GLYPH = {
    arrowup: '↑',
    arrowdown: '↓',
    arrowleft: '←',
    arrowright: '→',
    enter: 'Enter',
    escape: 'Esc',
    space: 'Space',
    tab: 'Tab',
    backspace: 'Backspace',
    delete: 'Del',
    pageup: 'PgUp',
    pagedown: 'PgDn',
    home: 'Home',
    end: 'End',
};

/**
 * Render a combo for display using the modifier symbols the user's own platform
 * prints on its keys: ⌘/⌥/⇧ on a Mac, Ctrl/Alt/Shift elsewhere. Section 4
 * rule 2 of the build brief requires the platform-correct label, and a Mac user
 * shown "Ctrl+A" will press the wrong key.
 */
export function prettyHint(key, opts) {
    if (typeof key !== 'string' || key === '') return '';
    const mac = opts && 'isMac' in opts ? opts.isMac : platform().isMac;
    if (GLYPH[key]) return GLYPH[key];
    const parts = key.split('+').map((p) => {
        if (p === 'ctrl') return mac ? '⌘' : 'Ctrl';
        if (p === 'alt') return mac ? '⌥' : 'Alt';
        if (p === 'shift') return mac ? '⇧' : 'Shift';
        if (p === 'meta') return mac ? '⌘' : 'Win';
        if (/^f\d{1,2}$/.test(p)) return p.toUpperCase();
        if (GLYPH[p]) return GLYPH[p];
        return p.length === 1 ? p.toUpperCase() : p.charAt(0).toUpperCase() + p.slice(1);
    });
    // Mac convention prints modifier symbols with no separator: ⌘A, ⌥⇧F6.
    return mac ? parts.join('') : parts.join('+');
}

/**
 * Tally's working date (yyyy-mm-dd), or null when none is set.
 *
 * Lives here beside the other session-scoped engine state so screens can read
 * it without reaching into the Alpine store — the voucher controller needs it
 * during data() construction, before $store is available.
 */
export function workingDate() {
    try {
        return window.sessionStorage.getItem('zb.workingDate') || null;
    } catch (_) {
        return null;
    }
}
