/* =========================================================================
   ZeroBook — installable-app support

   Why this exists at all: a browser TAB cannot take several of Tally's keys.
   F11 is the browser's fullscreen, F12 its DevTools, Ctrl+T a new tab, Alt+D
   the address bar. Installed as an app there is no address bar and no tab
   strip, so those keys come back to us — which is the whole point (shortcut
   doc §F.1, and the reason the approved build ships as a PWA).

   Two deliberate constraints:

   1. NEVER register a service worker inside ZeroBook Desktop. The Tauri shell
      already runs its own offline layer against local SQLite; a second,
      disagreeing offline layer is worse than none. It would also likely trip
      the shell's CSP.
   2. The install invitation is a quiet, dismissible affordance — not a modal.
      An accountant mid-voucher must never be interrupted by a banner.
   ========================================================================= */

import { isDesktopRuntime, platform } from './keys.js';

const DISMISSED = 'zb.install.dismissed';

/** The deferred beforeinstallprompt event, once the browser offers one. */
let deferredPrompt = null;

export function canInstall() {
    return deferredPrompt !== null;
}

/**
 * Show the browser's own install dialog. Returns the user's choice, or null
 * when no prompt is available (Safari never fires beforeinstallprompt — there
 * the user installs via Share ▸ Add to Dock / Add to Home Screen).
 */
export async function promptInstall() {
    if (!deferredPrompt) return null;
    const prompt = deferredPrompt;
    deferredPrompt = null; // a prompt may only be used once
    prompt.prompt();
    try {
        const { outcome } = await prompt.userChoice;
        return outcome;
    } catch (err) {
        console.error('[ZB] install prompt failed', err);
        return null;
    }
}

/** Has the user waved the invitation away before? */
export function installDismissed() {
    try {
        return window.localStorage.getItem(DISMISSED) === '1';
    } catch (_) {
        return false;
    }
}

export function dismissInstall() {
    try {
        window.localStorage.setItem(DISMISSED, '1');
    } catch (_) {
        /* private mode — the invitation simply reappears next session */
    }
}

/**
 * How this user would install, in their own words. Safari gives no
 * programmatic prompt, so it needs the manual recipe instead of a button.
 */
export function installHint() {
    const p = platform();
    if (p.browser === 'safari') {
        return p.isMac
            ? 'Safari ▸ File ▸ Add to Dock'
            : 'Share ▸ Add to Home Screen';
    }
    if (p.browser === 'firefox') {
        // Firefox desktop dropped PWA install; the keys it reserves stay reserved.
        return null;
    }
    return 'Install for full keyboard support';
}

export function startInstallSupport() {
    if (typeof window === 'undefined') return;

    // Inside the desktop shell there is nothing to install and a service
    // worker would collide with the shell's own offline layer.
    if (isDesktopRuntime()) return;

    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch((err) => {
                // Never fatal: without a worker the app still runs, it just
                // may not be offered for install.
                console.warn('[ZB] service worker registration failed', err);
            });
        });
    }

    window.addEventListener('beforeinstallprompt', (e) => {
        // Suppress the browser's own mini-infobar so the invitation appears
        // where we choose, not over a voucher.
        e.preventDefault();
        deferredPrompt = e;
        window.dispatchEvent(new CustomEvent('zb:installable'));
    });

    window.addEventListener('appinstalled', () => {
        deferredPrompt = null;
        dismissInstall();
        window.dispatchEvent(new CustomEvent('zb:installed'));
    });
}
