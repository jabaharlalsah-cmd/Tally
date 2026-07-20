/**
 * ZeroBook keyboard engine — key-mapping unit tests.
 *
 * Required by Section 4 rule 8 of the build brief: the mapping logic must be
 * covered for BOTH the Windows map and the macOS map. The macOS half is the
 * reason the previous build was rejected — every Ctrl shortcut was dead there
 * because ⌘ was never folded into Ctrl.
 *
 * Authority for the shortcut scheme:
 *   C:\laragon\www\New Account Software\_docs\keyboard-shortcuts.md
 */
import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    __resetDirty,
    __resetPlatformCache,
    combo,
    isBareChar,
    isDirty,
    isEditable,
    isReloadCombo,
    mayRepeat,
    prettyHint,
    registerDirty,
    shouldHardBlock,
    shouldIgnore,
} from './keys.js';
import { toMacLabel } from './labels.js';

/* ---- helpers ------------------------------------------------------------ */

function usePlatform(kind) {
    Object.defineProperty(navigator, 'platform', {
        value: kind === 'mac' ? 'MacIntel' : 'Win32',
        configurable: true,
    });
    Object.defineProperty(navigator, 'userAgentData', { value: undefined, configurable: true });
    Object.defineProperty(navigator, 'userAgent', {
        value: kind === 'mac'
            ? 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 Safari/605.1.15'
            : 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120 Safari/537.36',
        configurable: true,
    });
    vi.spyOn(window, 'matchMedia').mockImplementation(() => ({ matches: false }));
    __resetPlatformCache();
}

/** Derive the e.key a real keyboard would send, so tests mirror the browser. */
function defaultKeyFor(code) {
    let m = /^Key([A-Z])$/.exec(code);
    if (m) return m[1].toLowerCase();
    m = /^Digit([0-9])$/.exec(code);
    if (m) return m[1];
    if (/^F\d{1,2}$/.test(code)) return code;
    const named = { Escape: 'Escape', Enter: 'Enter', NumpadEnter: 'Enter', Space: ' ', Tab: 'Tab' };
    return named[code] || code;
}

function press(code, opts = {}) {
    const { altGraph, ...init } = opts;
    const e = new KeyboardEvent('keydown', {
        code,
        key: 'key' in init ? init.key : defaultKeyFor(code),
        ...init,
    });
    if (altGraph) {
        Object.defineProperty(e, 'getModifierState', {
            value: (m) => m === 'AltGraph',
            configurable: true,
        });
    }
    return e;
}

beforeEach(() => {
    __resetDirty();
});

/* ---- Windows map -------------------------------------------------------- */

describe('Windows key map', () => {
    beforeEach(() => usePlatform('win'));

    it('maps Ctrl combos', () => {
        expect(combo(press('KeyA', { ctrlKey: true }))).toBe('ctrl+a');
    });

    it('maps Alt combos', () => {
        expect(combo(press('KeyG', { altKey: true }))).toBe('alt+g');
    });

    it('maps function keys, plain and modified', () => {
        expect(combo(press('F5'))).toBe('f5');
        expect(combo(press('F6', { altKey: true }))).toBe('alt+f6');
        expect(combo(press('F8', { ctrlKey: true }))).toBe('ctrl+f8');
    });

    it('maps Ctrl+Alt combos in a fixed order', () => {
        expect(combo(press('KeyN', { ctrlKey: true, altKey: true }))).toBe('ctrl+alt+n');
    });

    it('treats a bare letter as a bare character', () => {
        expect(combo(press('KeyB'))).toBe('b');
        expect(isBareChar('b')).toBe(true);
        expect(isBareChar('ctrl+b')).toBe(false);
    });

    it('names the special keys', () => {
        expect(combo(press('Escape'))).toBe('escape');
        expect(combo(press('Enter'))).toBe('enter');
        expect(combo(press('NumpadEnter'))).toBe('enter');
    });

    it('ignores a held Windows key — that is the OS, not us', () => {
        expect(shouldIgnore(press('KeyA', { metaKey: true }))).toBe('os-meta');
    });
});

/* ---- macOS map — the regression that got the build rejected ------------- */

describe('macOS key map', () => {
    beforeEach(() => usePlatform('mac'));

    it('folds Command into Ctrl so ⌘A reaches the Ctrl+A binding', () => {
        // THE bug: this previously produced "meta+a", which matched nothing,
        // leaving every Ctrl shortcut dead for Mac users.
        expect(combo(press('KeyA', { metaKey: true }))).toBe('ctrl+a');
    });

    it('still maps a real Ctrl press', () => {
        expect(combo(press('KeyA', { ctrlKey: true }))).toBe('ctrl+a');
    });

    it('resolves Option combos through the physical key, not the composed glyph', () => {
        // macOS composes Option+C into "ç"; e.key is therefore useless here.
        expect(combo(press('KeyC', { altKey: true, key: 'ç' }))).toBe('alt+c');
        expect(combo(press('KeyG', { altKey: true, key: '©' }))).toBe('alt+g');
    });

    it.each([
        ['KeyQ', 'quit'],
        ['KeyW', 'close window'],
        ['KeyH', 'hide'],
        ['KeyM', 'minimise'],
        ['KeyN', 'new window'],
        ['KeyT', 'new tab'],
        ['KeyR', 'reload'],
    ])('leaves ⌘+%s to macOS (%s)', (code) => {
        expect(shouldIgnore(press(code, { metaKey: true }))).toBe('mac-reserved');
    });

    it('does not let ⌘R fall through to the Ctrl+R hard block', () => {
        // Regression: folding ⌘ into Ctrl made ⌘R produce "ctrl+r", which
        // HARD_BLOCK claims — silently breaking reload for every Mac user.
        expect(shouldIgnore(press('KeyR', { metaKey: true }))).toBe('mac-reserved');
        expect(isReloadCombo(press('KeyR', { metaKey: true }))).toBe(false);
    });

    it('still guards a real Ctrl+R on a Mac', () => {
        expect(shouldIgnore(press('KeyR', { ctrlKey: true }))).toBeNull();
        expect(isReloadCombo(press('KeyR', { ctrlKey: true }))).toBe(true);
    });

    it('keeps ⌘C/⌘V/⌘X/⌘Z native while typing in a field', () => {
        const input = document.createElement('input');
        document.body.appendChild(input);
        for (const code of ['KeyC', 'KeyV', 'KeyX', 'KeyZ']) {
            const e = press(code, { metaKey: true });
            Object.defineProperty(e, 'target', { value: input, configurable: true });
            expect(shouldIgnore(e)).toBe('native-edit');
        }
        input.remove();
    });

    it('owns ⌘A in a field — Tally Accept, per doc §A', () => {
        const input = document.createElement('input');
        document.body.appendChild(input);
        const e = press('KeyA', { metaKey: true });
        Object.defineProperty(e, 'target', { value: input, configurable: true });
        expect(shouldIgnore(e)).toBeNull();
        input.remove();
    });
});

/* ---- input-method and layout safety ------------------------------------- */

describe('input method and layout safety', () => {
    beforeEach(() => usePlatform('win'));

    it('never steals a keystroke mid-composition', () => {
        expect(shouldIgnore(press('KeyA', { isComposing: true }))).toBe('ime');
    });

    it('ignores the legacy IME keyCode 229', () => {
        const e = press('KeyA');
        Object.defineProperty(e, 'keyCode', { value: 229, configurable: true });
        expect(shouldIgnore(e)).toBe('ime');
    });

    it('ignores dead keys', () => {
        expect(shouldIgnore(press('Quote', { key: 'Dead' }))).toBe('ime');
    });

    it('ignores AltGr typing — Ctrl+Alt on European layouts is a character', () => {
        expect(shouldIgnore(press('KeyE', { ctrlKey: true, altKey: true, altGraph: true }))).toBe('altgr');
    });
});

/* ---- reload guard ------------------------------------------------------- */

describe('reload guard', () => {
    beforeEach(() => usePlatform('win'));

    it('claims F5 with any modifier, including the hard reloads', () => {
        expect(isReloadCombo(press('F5'))).toBe(true);
        expect(isReloadCombo(press('F5', { ctrlKey: true }))).toBe(true);
        expect(isReloadCombo(press('F5', { ctrlKey: true, shiftKey: true }))).toBe(true);
        expect(isReloadCombo(press('F5', { altKey: true }))).toBe(true);
    });

    it('claims Ctrl+R', () => {
        expect(isReloadCombo(press('KeyR', { ctrlKey: true }))).toBe(true);
        expect(shouldHardBlock('ctrl+r')).toBe(true);
    });

    it('leaves a bare R alone', () => {
        expect(isReloadCombo(press('KeyR'))).toBe(false);
    });
});

/* ---- auto-repeat policy ------------------------------------------------- */

describe('auto-repeat policy', () => {
    it('lets navigation keys repeat', () => {
        expect(mayRepeat('arrowdown')).toBe(true);
        expect(mayRepeat('pagedown')).toBe(true);
    });

    it('refuses to repeat action keys — holding F5 must not open 20 vouchers', () => {
        expect(mayRepeat('f5')).toBe(false);
        expect(mayRepeat('ctrl+a')).toBe(false);
        expect(mayRepeat('alt+f6')).toBe(false);
    });
});

/* ---- editable detection ------------------------------------------------- */

describe('editable target detection', () => {
    it('recognises fields the user types into', () => {
        const text = document.createElement('input');
        expect(isEditable(text)).toBe(true);
        const area = document.createElement('textarea');
        expect(isEditable(area)).toBe(true);
    });

    it('excludes non-typing inputs', () => {
        const box = document.createElement('input');
        box.setAttribute('type', 'checkbox');
        expect(isEditable(box)).toBe(false);
    });

    it('handles a null target', () => {
        expect(isEditable(null)).toBe(false);
    });
});

/* ---- on-screen hints ---------------------------------------------------- */

describe('on-screen hint rendering', () => {
    it('prints Ctrl/Alt on Windows', () => {
        usePlatform('win');
        expect(prettyHint('ctrl+a')).toBe('Ctrl+A');
        expect(prettyHint('alt+f6')).toBe('Alt+F6');
        expect(prettyHint('ctrl+alt+n')).toBe('Ctrl+Alt+N');
    });

    it('prints ⌘/⌥ on macOS, in Mac notation without separators', () => {
        usePlatform('mac');
        expect(prettyHint('ctrl+a')).toBe('⌘A');
        expect(prettyHint('alt+f6')).toBe('⌥F6');
        expect(prettyHint('ctrl+alt+n')).toBe('⌘⌥N');
    });

    it('renders named keys as glyphs on both platforms', () => {
        usePlatform('win');
        expect(prettyHint('escape')).toBe('Esc');
        expect(prettyHint('arrowdown')).toBe('↓');
    });
});

/* ---- Blade label localisation ------------------------------------------- */

describe('literal label localisation', () => {
    it('translates the modifier words baked into Blade', () => {
        expect(toMacLabel('Ctrl+A')).toBe('⌘A');
        expect(toMacLabel('Alt+F1')).toBe('⌥F1');
        expect(toMacLabel('Ctrl+Alt+N')).toBe('⌘⌥N');
        expect(toMacLabel('Shift+Tab')).toBe('⇧Tab');
    });

    it('leaves separators and non-modifier keys intact', () => {
        expect(toMacLabel('Ctrl+A · Esc')).toBe('⌘A · Esc');
        expect(toMacLabel('Alt+A · Ctrl+A · Esc')).toBe('⌥A · ⌘A · Esc');
        expect(toMacLabel('Enter')).toBe('Enter');
    });

    it('is idempotent — a second pass changes nothing', () => {
        const once = toMacLabel('Ctrl+A');
        expect(toMacLabel(once)).toBe(once);
    });
});

/* ---- unsaved-work guard ------------------------------------------------- */

describe('unsaved-work guard', () => {
    it('reports clean when nothing is registered', () => {
        expect(isDirty()).toBe(false);
    });

    it('reports dirty while a probe says so, and clean after it unregisters', () => {
        const off = registerDirty('voucher', () => true);
        expect(isDirty()).toBe(true);
        off();
        expect(isDirty()).toBe(false);
    });

    it('survives a probe that throws', () => {
        registerDirty('bad', () => {
            throw new Error('boom');
        });
        vi.spyOn(console, 'error').mockImplementation(() => {});
        expect(isDirty()).toBe(false);
    });

    it('is dirty when any one of several probes is dirty', () => {
        registerDirty('a', () => false);
        registerDirty('b', () => true);
        expect(isDirty()).toBe(true);
    });
});
