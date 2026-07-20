/* =========================================================================
   ZeroBook keyboard engine — on-screen shortcut label localisation

   Every shortcut hint in the app is rendered inside a `.zb-kbd` element. Most
   of those are literal text baked into Blade ("Ctrl+A", "Alt+F1") across 68
   view files, because for a Windows user that IS the correct label.

   On a Mac it is not: the user's keyboard is printed ⌘ / ⌥ / ⇧, and the engine
   folds Command into Ctrl so ⌘A really is Accept. Showing "Ctrl+A" there sends
   the user hunting for a key that does nothing.

   Rewriting 362 literals across 68 Blade files by hand would be both enormous
   and fragile — and a PARTIAL rewrite is worse than none, because the screen
   would then show ⌘A in the button bar and Ctrl+A in the footer at the same
   time. So this does it in one place, at runtime, for every `.zb-kbd` on the
   page including ones Livewire renders later.

   Windows and Linux are untouched: the function returns immediately.
   ========================================================================= */

import { platform } from './keys.js';

/** Remembers each element's authored text so re-runs are idempotent. */
const ORIGINAL = 'zbKbdSource';

const MODIFIER = [
    [/\bCtrl\s*\+\s*/gi, '⌘'],
    [/\bControl\s*\+\s*/gi, '⌘'],
    [/\bAlt\s*\+\s*/gi, '⌥'],
    [/\bOption\s*\+\s*/gi, '⌥'],
    [/\bShift\s*\+\s*/gi, '⇧'],
    // Bare words, for prose like "hold Ctrl" — applied after the combo forms so
    // "Ctrl+A" is already gone by the time these run.
    [/\bCtrl\b/gi, '⌘'],
    [/\bAlt\b/gi, '⌥'],
    [/\bShift\b/gi, '⇧'],
];

/**
 * Translate one authored label to Mac notation.
 * "Ctrl+A" → "⌘A" · "Alt+F1" → "⌥F1" · "Ctrl+Alt+N" → "⌘⌥N"
 * "Ctrl+A · Esc" → "⌘A · Esc" (separators and non-modifier keys untouched)
 */
export function toMacLabel(text) {
    let out = String(text);
    for (const [pattern, glyph] of MODIFIER) out = out.replace(pattern, glyph);
    return out;
}

/**
 * Set while we write, so the observer can tell OUR edits apart from a genuine
 * re-render. Without this, writing the translated text fires characterData,
 * which would discard the remembered original and treat "⌘A" as the source.
 */
let writing = false;

function applyTo(el) {
    if (!el || !el.dataset) return;
    // First sighting: remember what Blade authored, so later passes translate
    // the original rather than compounding on an already-translated string.
    if (el.dataset[ORIGINAL] === undefined) {
        el.dataset[ORIGINAL] = el.textContent;
    }
    const source = el.dataset[ORIGINAL];
    const translated = toMacLabel(source);
    if (el.textContent === translated) return;
    writing = true;
    try {
        el.textContent = translated;
    } finally {
        writing = false;
    }
}

/** Translate every `.zb-kbd` inside `root` (inclusive). */
export function localiseLabels(root) {
    const scope = root || document;
    if (scope.nodeType === 1 && scope.classList && scope.classList.contains('zb-kbd')) {
        applyTo(scope);
    }
    if (typeof scope.querySelectorAll !== 'function') return;
    scope.querySelectorAll('.zb-kbd').forEach(applyTo);
}

let started = false;

/**
 * Start translating shortcut labels, now and for anything added later.
 *
 * A MutationObserver rather than a Livewire hook on purpose: labels arrive from
 * Livewire morphs, Alpine templates, and plain Blade includes alike, and the
 * observer catches all three without depending on any one framework's
 * lifecycle. No-op on Windows and Linux.
 */
export function startLabelLocalisation() {
    if (started) return;
    if (!platform().isMac) return; // Windows/Linux labels are already correct
    if (typeof document === 'undefined') return;
    started = true;

    const run = () => localiseLabels(document);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', run, { once: true });
    } else {
        run();
    }

    const observer = new MutationObserver((records) => {
        if (writing) return; // our own edit, not a re-render
        for (const record of records) {
            if (record.type === 'characterData') {
                // Text swapped inside an existing chip (x-text, or a morph that
                // reused the node): re-read it as the new authored source.
                const el = record.target.parentElement;
                if (el && el.classList && el.classList.contains('zb-kbd')) {
                    delete el.dataset[ORIGINAL];
                    applyTo(el);
                }
                continue;
            }
            record.addedNodes.forEach((node) => {
                if (node.nodeType === 1) localiseLabels(node);
            });
        }
    });
    observer.observe(document.documentElement, {
        childList: true,
        subtree: true,
        characterData: true,
    });

    // Livewire replaces whole page bodies on wire:navigate; the observer above
    // sees those as added nodes, but a navigation can also restore a cached
    // body wholesale, so re-run explicitly on the navigation events too.
    window.addEventListener('livewire:navigated', run);
}

/** Test hook — allows a fresh start in unit tests. */
export function __resetLabelLocalisation() {
    started = false;
}
