/**
 * ZeroBook reports — TallyPrime drill-down behaviour.
 *
 * The contract being locked in: Enter on a GROUP expands it, Enter on a LEDGER
 * drills to its vouchers, and Esc comes back up landing on THE ROW YOU LEFT.
 * That last part is what makes a long Trial Balance workable — without it every
 * Esc dumps the operator at row one and they have to find their place again.
 */
import { expect, test } from '@playwright/test';

const EMAIL = process.env.ZB_E2E_EMAIL || 'e2e@zerobook.test';
const PASSWORD = process.env.ZB_E2E_PASSWORD || 'e2e-playwright-local';

async function login(page) {
    await page.goto('/login');
    await page.fill('#email', EMAIL);
    await page.fill('#password', PASSWORD);
    await page.click('button[type=submit], input[type=submit]');
    await page.waitForURL(/\/app|\/companies/, { timeout: 15_000 });
}

/**
 * Evaluate against the report component. The page has several x-data roots
 * (layout, shell, report), so it is found by capability rather than by
 * position — querySelector('[x-data]') returns the layout and silently
 * gives the wrong object.
 */
function reportEval(page, expr) {
    return page.evaluate(([src]) => {
        const el = Array.from(document.querySelectorAll('[x-data]')).find((e) => {
            try {
                return typeof window.Alpine.$data(e).navRows === 'function';
            } catch (_) {
                return false;
            }
        });
        if (!el) return null;
        const d = window.Alpine.$data(el);
        return eval(src);
    }, [expr]);
}

async function trialBalance(page) {
    await login(page);
    await page.goto('/reports/trial-balance');
    await page.waitForFunction(() => window.Alpine?.store?.('zb'));
    await expect.poll(() => reportEval(page, 'd.activeKey')).not.toBeNull();
}

test.describe('Report drill-down', () => {
    test('Enter on a group expands it rather than navigating', async ({ page }) => {
        await trialBalance(page);
        const before = page.url();
        // The Trial Balance opens on group rows; Enter must expand, as in Tally.
        // Polled, not read once: activeKey is set before rowsByKey is fully
        // populated, so under load a single read can see the key but not yet the
        // row. That produced a failure only when both browser projects ran
        // together — green in isolation, red under load.
        await expect.poll(() => reportEval(page, "d.row(d.activeKey)?.kind")).toBe('group');
        await page.keyboard.press('Enter');
        await page.waitForTimeout(400);
        expect(page.url()).toBe(before);
    });

    test('Alt+F1 reveals ledger rows', async ({ page }) => {
        await trialBalance(page);
        expect(await reportEval(page, 'd.detailed')).toBe(false);
        await page.keyboard.press('Alt+F1');
        await expect.poll(() => reportEval(page, 'd.detailed')).toBe(true);
        await expect
            .poll(() => reportEval(page, "d.navRows().some(r => r.kind === 'ledger')"))
            .toBe(true);
    });

    test('Enter on a ledger drills to its vouchers, and Esc returns to that row', async ({ page }) => {
        await trialBalance(page);
        await page.keyboard.press('Alt+F1');
        await expect.poll(() => reportEval(page, 'd.detailed')).toBe(true);

        const row = await reportEval(page, "d.navRows().find(r => r.kind === 'ledger')?.key");
        test.skip(!row, 'no ledger row in this company');

        await page.evaluate(([k]) => {
            const el = Array.from(document.querySelectorAll('[x-data]')).find((e) => {
                try {
                    return typeof window.Alpine.$data(e).navRows === 'function';
                } catch (_) {
                    return false;
                }
            });
            window.Alpine.$data(el).activeKey = k;
        }, [row]);

        await page.keyboard.press('Enter');
        await page.waitForURL(/reports\/ledger\/\d+\/vouchers/, { timeout: 10_000 });

        await page.keyboard.press('Escape');
        await page.waitForURL(/reports\/trial-balance/, { timeout: 10_000 });

        // The whole point: back on the line we left, still detailed.
        await expect.poll(() => reportEval(page, 'd.activeKey')).toBe(row);
        expect(await reportEval(page, 'd.detailed')).toBe(true);
    });

    test('entering the report fresh does NOT resurrect an old cursor', async ({ page }) => {
        await trialBalance(page);
        await page.keyboard.press('Alt+F1');
        await expect.poll(() => reportEval(page, 'd.detailed')).toBe(true);
        const row = await reportEval(page, "d.navRows().find(r => r.kind === 'ledger')?.key");
        test.skip(!row, 'no ledger row in this company');

        await page.evaluate(([k]) => {
            const el = Array.from(document.querySelectorAll('[x-data]')).find((e) => {
                try {
                    return typeof window.Alpine.$data(e).navRows === 'function';
                } catch (_) {
                    return false;
                }
            });
            window.Alpine.$data(el).activeKey = k;
        }, [row]);
        await page.keyboard.press('Enter');
        await page.waitForURL(/reports\/ledger\/\d+\/vouchers/, { timeout: 10_000 });

        // Leave via the Gateway instead of Esc, then come back in fresh. The
        // stashed position is consumed on read, so it must not reappear.
        await page.goto('/app');
        await page.goto('/reports/trial-balance');
        await page.waitForFunction(() => window.Alpine?.store?.('zb'));
        await expect.poll(() => reportEval(page, 'd.activeKey')).not.toBe(row);
    });
});

test.describe('F2 working date', () => {
    test('the date set with F2 is what a NEW voucher opens on', async ({ page }) => {
        await login(page);

        // F2 anywhere opens Tally's date/period gate.
        await page.keyboard.press('F2');
        await expect.poll(() => page.evaluate(() => window.Alpine.store('zb').period.open)).toBe(true);

        await page.evaluate(() => {
            const el = Array.from(document.querySelectorAll('[x-data]')).find((e) => {
                try {
                    const d = window.Alpine.$data(e);
                    return 'from' in d && 'to' in d && typeof d.apply === 'function';
                } catch (_) {
                    return false;
                }
            });
            const d = window.Alpine.$data(el);
            d.from = '2026-05-01';
            d.to = '2026-05-15';
            d.apply();
        });

        // It used to write only a top-bar label: the screen confirmed the date
        // and every new voucher then ignored it and used today. Worse than the
        // key doing nothing, because it looks like it worked.
        await page.goto('/vouchers/create/payment');
        await page.waitForFunction(() => window.ZB_VOUCHER);
        await expect.poll(() => page.evaluate(() => window.ZB_VOUCHER.date)).toBe('2026-05-15');
    });

    test('ALTERING a voucher keeps its own date, not the working date', async ({ page }) => {
        await login(page);
        await page.evaluate(() => window.sessionStorage.setItem('zb.workingDate', '2026-05-15'));

        // Open a saved voucher the way an operator does: Enter on a Day Book
        // row. The rows are not anchors and the component reads the id from the
        // DOM, so driving the real keystroke is both simpler and truer than
        // reconstructing the URL.
        await page.goto('/day-book');
        await page.waitForFunction(() => window.Alpine?.store?.('zb'));
        const rows = await page.locator('#daybook-list .zb-list-item, #daybook-list tr').count();
        test.skip(rows === 0, 'no voucher to alter in this company');

        await page.keyboard.press('Enter');
        await page.waitForURL(/vouchers\/\d+\/alter/, { timeout: 10_000 });
        await page.waitForFunction(() => window.ZB_VOUCHER);

        // A saved voucher's date is its own. The working date must never
        // silently rewrite an existing entry.
        expect(await page.evaluate(() => window.ZB_VOUCHER.date)).not.toBe('2026-05-15');
    });

    test('Alt+F2 on a report focuses the period rather than doing nothing', async ({ page }) => {
        await login(page);
        await page.goto('/reports/trial-balance');
        await page.waitForFunction(() => window.Alpine?.store?.('zb'));

        await page.keyboard.press('Alt+F2');
        // Previously this fell through to a global stub that only relabelled the
        // top bar and left the report period untouched.
        await expect(page.locator('#report-from')).toBeFocused();
        expect(await page.evaluate(() => window.Alpine.store('zb').period.open)).toBe(false);
    });
});
