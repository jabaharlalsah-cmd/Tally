/**
 * ZeroBook Gateway — the four-section arrangement of the approved build.
 *
 * These assert the ARRANGEMENT (sections, order, hot letters, feature gating),
 * which is the thing Phase 2 changed, and the properties the old flat menu got
 * wrong: duplicate hot letters and unreachable entries.
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

async function gateway(page) {
    await login(page);
    await page.goto('/app');
    await page.waitForFunction(() => window.Alpine?.store?.('zb'));
    await expect(page.locator('.zb-menu-item').first()).toBeVisible();
}

test.describe('Gateway arrangement', () => {
    test('shows the four approved sections in order', async ({ page }) => {
        await gateway(page);
        const sections = await page.locator('.zb-menu-section').allTextContents();
        expect(sections.map((s) => s.trim())).toEqual([
            'Masters',
            'Transactions',
            'Utilities',
            'Reports',
        ]);
    });

    test('every hot letter on the screen is unique', async ({ page }) => {
        await gateway(page);
        // The old flat menu bound 'C' and 'D' twice and '0' three times, so two
        // entries were silently unreachable by their advertised letter.
        const letters = await page.evaluate(() =>
            (window.Alpine.$data(document.getElementById('zb-gateway')).items || []).map((i) => i.letter)
        );
        expect(letters.length).toBeGreaterThan(0);
        expect(new Set(letters).size).toBe(letters.length);
    });

    test('every item shows a highlighted letter that is really in its label', async ({ page }) => {
        await gateway(page);
        // hotLabel() degrades silently when the letter is absent from the label,
        // leaving nothing highlighted — the old menu did that for 13 of 40 rows
        // while instructing users to "press an item's highlighted letter".
        const bad = await page.evaluate(() =>
            (window.Alpine.$data(document.getElementById('zb-gateway')).items || [])
                .filter((i) => !i.label.toLowerCase().includes(i.letter.toLowerCase()))
                .map((i) => `${i.letter}:${i.label}`)
        );
        expect(bad).toEqual([]);
    });

    test('every Display More Reports item has a real highlighted letter', async ({ page }) => {
        await login(page);
        await page.goto('/reports/more');
        await page.waitForFunction(() => window.Alpine?.store?.('zb'));
        const bad = await page.evaluate(() =>
            (window.Alpine.$data(document.getElementById('zb-reports-hub')).items || [])
                .filter((i) => !i.label.toLowerCase().includes(i.letter.toLowerCase()))
                .map((i) => `${i.letter}:${i.label}`)
        );
        expect(bad).toEqual([]);
    });

    test('Balance Sheet keeps B, and Display More Reports is present', async ({ page }) => {
        await gateway(page);
        const items = await page.evaluate(() =>
            (window.Alpine.$data(document.getElementById('zb-gateway')).items || []).map(
                (i) => `${i.letter}|${i.label}`
            )
        );
        expect(items).toContain('B|Balance Sheet');
        expect(items).toContain('P|Profit & Loss A/c');
        expect(items).toContain('M|Display More Reports');
        expect(items).toContain('V|Vouchers');
        expect(items).toContain('D|Day Book');
    });

    test('an accounts-only company sees no inventory entries', async ({ page }) => {
        await gateway(page);
        // The demo company has the inventory flag off, so the whole inventory
        // side of the Gateway must be absent rather than dead-ending.
        const labels = await page.evaluate(() =>
            (window.Alpine.$data(document.getElementById('zb-gateway')).items || []).map((i) => i.label)
        );
        expect(labels).not.toContain('Inventory Info');
        expect(labels).not.toContain('Stock Summary');
    });

    test('the developer harness is not on the Gateway', async ({ page }) => {
        await gateway(page);
        const labels = await page.evaluate(() =>
            (window.Alpine.$data(document.getElementById('zb-gateway')).items || []).map((i) => i.label)
        );
        expect(labels).not.toContain('Keyboard Harness');
    });

    test('M drills into Display More Reports and Esc returns', async ({ page }) => {
        await gateway(page);
        await page.keyboard.press('m');
        await page.waitForURL(/reports\/more/, { timeout: 10_000 });
        await expect(page.locator('.zb-menu-section').first()).toBeVisible();

        await page.keyboard.press('Escape');
        await page.waitForURL(/\/app$/, { timeout: 10_000 });
    });

    test('Display More Reports groups reports and keeps letters unique', async ({ page }) => {
        await login(page);
        await page.goto('/reports/more');
        await page.waitForFunction(() => window.Alpine?.store?.('zb'));
        await expect(page.locator('.zb-menu-item').first()).toBeVisible();

        const sections = await page.locator('.zb-menu-section').allTextContents();
        expect(sections.map((s) => s.trim())).toContain('Account Books');
        expect(sections.map((s) => s.trim())).toContain('Statements of Accounts');

        const letters = await page.evaluate(() =>
            (window.Alpine.$data(document.getElementById('zb-reports-hub')).items || []).map((i) => i.letter)
        );
        expect(new Set(letters).size).toBe(letters.length);
    });

    test('Masters is Create / Alter / Chart of Accounts, as in TallyPrime', async ({ page }) => {
        await gateway(page);
        const items = await page.evaluate(() =>
            (window.Alpine.$data(document.getElementById('zb-gateway')).items || []).map(
                (i) => `${i.letter}|${i.label}`
            )
        );
        expect(items).toContain('C|Create');
        expect(items).toContain('A|Alter');
        expect(items).toContain('H|Chart of Accounts');
        // The individual masters are reached THROUGH those, not listed beside them.
        expect(items).not.toContain('I|Inventory Info');
        expect(items).not.toContain('U|Currencies');
    });

    test('C opens the Create chooser and Esc returns to the Gateway', async ({ page }) => {
        await gateway(page);
        await page.keyboard.press('c');
        await page.waitForURL(/masters\/create/, { timeout: 10_000 });
        const sections = await page.locator('.zb-menu-section').allTextContents();
        expect(sections.map((s) => s.trim())).toContain('Accounting Masters');

        await page.keyboard.press('Escape');
        await page.waitForURL(/\/app$/, { timeout: 10_000 });
    });

    test('A opens the Alter chooser', async ({ page }) => {
        await gateway(page);
        await page.keyboard.press('a');
        await page.waitForURL(/masters\/alter/, { timeout: 10_000 });
        await expect(page.locator('.zb-menu-item').first()).toBeVisible();
    });

    test('the chooser deep-links past the workspace menu into the right mode', async ({ page }) => {
        await login(page);
        await page.goto('/masters/create');
        await page.waitForFunction(() => window.Alpine?.store?.('zb'));
        // L is Ledger. Create mode must land on the create form, not the hub menu.
        await page.keyboard.press('l');
        await page.waitForURL(/masters\/ledgers\?mode=create/, { timeout: 10_000 });
        // Assert what the operator actually sees: the create form, focused and
        // ready — not the workspace's own hub menu.
        await expect(page.locator('form[data-zb-form="ledger"]')).toBeVisible();
        await expect(page.locator('#ws-menu')).toBeHidden();
        await expect(page.locator('#l-name')).toBeFocused();
    });

    test('the Alter chooser deep-links to the list, not the create form', async ({ page }) => {
        await login(page);
        await page.goto('/masters/alter');
        await page.waitForFunction(() => window.Alpine?.store?.('zb'));
        await page.keyboard.press('l');
        await page.waitForURL(/masters\/ledgers\?mode=alter/, { timeout: 10_000 });
        // In Tally the list IS the alter surface: pick a master, Enter alters it.
        await expect(page.locator('.zb-ws-browse')).toBeVisible();
        await expect(page.locator('form[data-zb-form="ledger"]')).toBeHidden();
    });

    test('chooser hot letters are unique and present in their labels', async ({ page }) => {
        await login(page); // once — the session carries across both modes
        for (const mode of ['create', 'alter']) {
            await page.goto(`/masters/${mode}`);
            await page.waitForFunction(() => window.Alpine?.store?.('zb'));
            const items = await page.evaluate(
                () => window.Alpine.$data(document.getElementById('zb-master-chooser')).items || []
            );
            const letters = items.map((i) => i.letter);
            expect(new Set(letters).size, `${mode}: duplicate letters`).toBe(letters.length);
            const bad = items
                .filter((i) => !i.label.toLowerCase().includes(i.letter.toLowerCase()))
                .map((i) => `${i.letter}:${i.label}`);
            expect(bad, `${mode}: letter not in label`).toEqual([]);
        }
    });

    test('arrow navigation skips section headings', async ({ page }) => {
        await gateway(page);
        // Sections are rows in the same list; the highlight must never land on one.
        for (let i = 0; i < 6; i++) {
            await page.keyboard.press('ArrowDown');
        }
        const activeIsItem = await page.evaluate(() => {
            const el = document.querySelector('.zb-menu-item.is-active');
            return !!el && !el.classList.contains('zb-menu-section');
        });
        expect(activeIsItem).toBe(true);
    });
});
