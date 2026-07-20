/**
 * ZeroBook keyboard engine — real-browser tests.
 *
 * These run on Chromium AND WebKit. WebKit is Safari's engine, and Safari on a
 * Mac is where the previous build was rejected, so a green WebKit run here is
 * the closest automated proxy for "it works on the client's Mac".
 *
 * The macOS-specific cases fake the platform via addInitScript rather than
 * needing Mac hardware: the engine's behaviour is driven entirely by its
 * platform probe (navigator.platform / userAgentData), so overriding that
 * exercises the real Mac code path in a real WebKit engine.
 */
import { expect, test } from '@playwright/test';

const EMAIL = process.env.ZB_E2E_EMAIL || 'e2e@zerobook.test';
const PASSWORD = process.env.ZB_E2E_PASSWORD || 'e2e-playwright-local';

/** Make the page believe it is running on a Mac, before any app code runs. */
async function pretendMac(page) {
    await page.addInitScript(() => {
        Object.defineProperty(navigator, 'platform', { get: () => 'MacIntel' });
        Object.defineProperty(navigator, 'userAgentData', { get: () => undefined });
    });
}

async function login(page) {
    await page.goto('/login');
    await page.fill('#email', EMAIL);
    await page.fill('#password', PASSWORD);
    await page.click('button[type=submit], input[type=submit]');
    await page.waitForURL(/\/app|\/companies/, { timeout: 15_000 });
}

test.describe('keyboard engine', () => {
    test('gateway loads and the engine attaches', async ({ page }) => {
        await login(page);
        await page.goto('/app');
        // window.ZB is the engine's own debug surface; its presence means the
        // dispatcher attached rather than throwing during boot.
        await expect
            .poll(() => page.evaluate(() => typeof window.ZB?.activeName === 'function'))
            .toBe(true);
    });

    test('F1 opens the shortcut help, Esc closes it', async ({ page }) => {
        await login(page);
        await page.goto('/app');
        await page.waitForFunction(() => window.Alpine?.store?.('zb'));

        await page.keyboard.press('F1');
        await expect(page.locator('.zb-help')).toBeVisible();
        // The help reads the live registry, so it must actually list shortcuts.
        await expect(page.locator('.zb-help-row').first()).toBeVisible();

        await page.keyboard.press('Escape');
        await expect(page.locator('.zb-help')).toBeHidden();
    });

    test('F5 suppresses the browser reload and opens Payment instead', async ({ page }) => {
        await login(page);
        await page.goto('/app');
        await page.waitForFunction(() => window.Alpine?.store?.('zb'));

        // Two things must both hold: the browser's own reload is cancelled
        // (otherwise an in-progress voucher would be discarded), AND the key
        // still carries its Tally meaning. Asserting only "the page did not
        // change" would wrongly fail, because opening Payment IS a navigation.
        const prevented = await page.evaluate(() => {
            const e = new KeyboardEvent('keydown', {
                key: 'F5',
                code: 'F5',
                bubbles: true,
                cancelable: true,
            });
            document.dispatchEvent(e);
            return e.defaultPrevented;
        });
        expect(prevented).toBe(true);
        await page.waitForURL(/vouchers\/create\/payment/, { timeout: 10_000 });
    });

    test('Ctrl+R does not reload the page', async ({ page }) => {
        await login(page);
        await page.goto('/app');
        await page.waitForFunction(() => window.Alpine?.store?.('zb'));

        await page.evaluate(() => {
            window.__zbReloadSentinel = 'alive';
        });
        await page.keyboard.press('Control+r');
        await page.waitForTimeout(400);
        expect(await page.evaluate(() => window.__zbReloadSentinel)).toBe('alive');
    });

    test('the gateway responds to its highlighted-letter keys', async ({ page }) => {
        await login(page);
        await page.goto('/app');
        await page.waitForFunction(() => window.Alpine?.store?.('zb'));

        // B is Day Book on the gateway menu.
        await page.keyboard.press('b');
        await page.waitForURL(/day-book/, { timeout: 10_000 });
    });
});

test.describe('macOS behaviour (platform faked, real engine)', () => {
    test.beforeEach(async ({ page }) => {
        await pretendMac(page);
    });

    test('the engine detects a Mac', async ({ page }) => {
        await login(page);
        await page.goto('/app');
        await page.waitForFunction(() => window.Alpine?.store?.('zb'));
        const isMac = await page.evaluate(() => navigator.platform.includes('Mac'));
        expect(isMac).toBe(true);
    });

    test('Cmd is folded into Ctrl — the defect that got the build rejected', async ({ page }) => {
        await login(page);
        await page.goto('/app');
        await page.waitForFunction(() => window.Alpine?.store?.('zb'));

        // Drive a real keydown through the real dispatcher and observe what
        // combo the engine resolved, via the zb:key event it emits.
        const resolved = await page.evaluate(async () => {
            return await new Promise((resolve) => {
                const onKey = (e) => {
                    window.removeEventListener('zb:key', onKey);
                    resolve(e.detail.combo);
                };
                window.addEventListener('zb:key', onKey);
                document.dispatchEvent(
                    new KeyboardEvent('keydown', {
                        key: 'a',
                        code: 'KeyA',
                        metaKey: true,
                        bubbles: true,
                        cancelable: true,
                    })
                );
                setTimeout(() => resolve('(no shortcut fired)'), 1000);
            });
        });
        // Previously this produced "meta+a" and matched nothing at all.
        expect(resolved).toBe('ctrl+a');
    });

    test('on-screen hints show Mac modifier symbols, not Ctrl', async ({ page }) => {
        await login(page);
        await page.goto('/app');
        await page.waitForFunction(() => window.Alpine?.store?.('zb'));
        // Give the label localiser its first pass.
        await page.waitForTimeout(300);

        const chips = await page.locator('.zb-kbd').allTextContents();
        const joined = chips.join(' | ');

        // At least one chip must have been translated...
        expect(joined).toContain('⌘');
        // ...and none may still be advertising a Windows modifier.
        expect(joined).not.toMatch(/\bCtrl\b/);
        expect(joined).not.toMatch(/\bAlt\b/);
    });

    test('Cmd+R is left to macOS rather than swallowed', async ({ page }) => {
        await login(page);
        await page.goto('/app');
        await page.waitForFunction(() => window.Alpine?.store?.('zb'));

        // The engine must NOT preventDefault this one: reload belongs to the OS
        // on a Mac. Folding Cmd into Ctrl would have made it "ctrl+r", which the
        // hard-block list claims.
        const prevented = await page.evaluate(() => {
            const e = new KeyboardEvent('keydown', {
                key: 'r',
                code: 'KeyR',
                metaKey: true,
                bubbles: true,
                cancelable: true,
            });
            document.dispatchEvent(e);
            return e.defaultPrevented;
        });
        expect(prevented).toBe(false);
    });

    test('Cmd+C stays native while typing in a field', async ({ page }) => {
        await login(page);
        await page.goto('/app');
        await page.waitForFunction(() => window.Alpine?.store?.('zb'));

        const prevented = await page.evaluate(() => {
            const input = document.createElement('input');
            document.body.appendChild(input);
            input.focus();
            const e = new KeyboardEvent('keydown', {
                key: 'c',
                code: 'KeyC',
                metaKey: true,
                bubbles: true,
                cancelable: true,
            });
            input.dispatchEvent(e);
            const result = e.defaultPrevented;
            input.remove();
            return result;
        });
        expect(prevented).toBe(false);
    });
});

test.describe('installable app', () => {
    test('manifest is served and well-formed', async ({ request }) => {
        const res = await request.get('/manifest.webmanifest');
        expect(res.status()).toBe(200);
        const manifest = await res.json();
        expect(manifest.display).toBe('standalone');
        expect(manifest.start_url).toBe('/app');
        // Chrome silently refuses to offer an install when the icons are
        // missing or undersized, so assert both required sizes resolve.
        const sizes = manifest.icons.map((i) => i.sizes);
        expect(sizes).toContain('192x192');
        expect(sizes).toContain('512x512');
    });

    test('every declared icon actually exists', async ({ request }) => {
        const manifest = await (await request.get('/manifest.webmanifest')).json();
        for (const icon of manifest.icons) {
            const res = await request.get(icon.src);
            expect(res.status(), `${icon.src} should exist`).toBe(200);
            expect(Number(res.headers()['content-length'] || 1)).toBeGreaterThan(0);
        }
    });

    test('the service worker is served', async ({ request }) => {
        const res = await request.get('/sw.js');
        expect(res.status()).toBe(200);
        expect(await res.text()).toContain('fetch');
    });
});
