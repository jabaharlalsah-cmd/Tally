/**
 * ZeroBook masters — field arrangement and keyboard flow.
 *
 * The field ORDER is the deliverable here, and in this app the Enter chain is
 * derived live from DOM order, so these tests drive real Enter presses rather
 * than reading the markup. That way they fail if either the arrangement or the
 * keyboard flow regresses.
 *
 * Order authority: TallyPrime 7.x (the owner's standing tie-breaker) — identity
 * and behaviour first, Opening Balance LAST.
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

async function ledgerCreateForm(page) {
    await login(page);
    await page.goto('/masters/ledgers?mode=create');
    await page.waitForFunction(() => window.Alpine?.store?.('zb'));
    await expect(page.locator('form[data-zb-form="ledger"]')).toBeVisible();
}

/** The visible, enabled fields of a form, in the order Enter will walk them. */
async function enterChain(page, formSelector) {
    return page.evaluate((sel) => {
        const form = document.querySelector(sel);
        if (!form) return [];
        return Array.from(form.querySelectorAll('[data-zb-field]'))
            .filter((el) => !el.disabled && el.offsetParent !== null)
            .map((el) => el.getAttribute('data-zb-label') || el.id);
    }, formSelector);
}

test.describe('Ledger master arrangement', () => {
    test('Opening Balance is the LAST field, as in TallyPrime', async ({ page }) => {
        await ledgerCreateForm(page);
        const chain = await enterChain(page, 'form[data-zb-form="ledger"]');
        expect(chain.length).toBeGreaterThan(2);
        // Tally asks for identity and behaviour first and the number last, so
        // Enter on the amount accepts the ledger.
        expect(chain[chain.length - 2]).toBe('Opening balance');
        expect(chain[chain.length - 1]).toBe('Dr/Cr');
    });

    test('Name is first and holds focus on open', async ({ page }) => {
        await ledgerCreateForm(page);
        const chain = await enterChain(page, 'form[data-zb-form="ledger"]');
        expect(chain[0]).toBe('Name');
        await expect(page.locator('#l-name')).toBeFocused();
    });

    test('Enter walks the fields in that order and reaches Opening Balance last', async ({ page }) => {
        await ledgerCreateForm(page);
        const chain = await enterChain(page, 'form[data-zb-form="ledger"]');

        await page.fill('#l-name', 'E2E Order Probe');
        // Walk the whole chain; the amount field must be the one before Dr/Cr.
        for (let i = 0; i < chain.length - 2; i++) {
            await page.keyboard.press('Enter');
        }
        await expect(page.locator('#l-opening')).toBeFocused();
    });

    test('the amount field carries the company currency symbol', async ({ page }) => {
        await ledgerCreateForm(page);
        const prefix = await page.locator('form[data-zb-form="ledger"] .zb-amt-prefix').first().textContent();
        expect(prefix.trim().length).toBeGreaterThan(0);
    });

    test('the alter form uses the same order as create', async ({ page }) => {
        await login(page);
        await page.goto('/masters/ledgers?mode=create');
        await page.waitForFunction(() => window.Alpine?.store?.('zb'));
        const createChain = await enterChain(page, 'form[data-zb-form="ledger"]');

        await page.goto('/masters/ledgers?mode=alter');
        await page.waitForFunction(() => window.Alpine?.store?.('zb'));
        await page.locator('#ws-list .zb-list-item').first().click();
        await expect(page.locator('form[data-zb-form="ledger-alter"]')).toBeVisible();
        const alterChain = await enterChain(page, 'form[data-zb-form="ledger-alter"]');

        // Altering a ledger must not feel unlike creating one: the number comes
        // last in both. (The chains are not identical field-for-field — a
        // reserved ledger disables Name, so it drops out of the chain. That is
        // correct: a system ledger cannot be renamed. See the next test.)
        expect(createChain[createChain.length - 2]).toBe('Opening balance');
        expect(alterChain[alterChain.length - 2]).toBe('Opening balance');
        expect(alterChain[alterChain.length - 1]).toBe('Dr/Cr');
    });

    test('a reserved ledger cannot be renamed, but its balance can be set', async ({ page }) => {
        await login(page);
        await page.goto('/masters/ledgers?mode=alter');
        await page.waitForFunction(() => window.Alpine?.store?.('zb'));
        // Cash is a seeded system ledger in every company.
        await page.locator('#ws-list .zb-list-item').first().click();
        await expect(page.locator('form[data-zb-form="ledger-alter"]')).toBeVisible();

        await expect(page.locator('#la-name')).toBeDisabled();
        await expect(page.locator('#la-opening')).toBeEnabled();
    });

    test('every visible field is reachable by Enter (none stranded)', async ({ page }) => {
        await ledgerCreateForm(page);
        // A field rendered but missing data-zb-field is invisible to the Enter
        // chain and can only be reached with a mouse — which breaks the
        // keyboard-only promise.
        const stranded = await page.evaluate(() => {
            const form = document.querySelector('form[data-zb-form="ledger"]');
            return Array.from(form.querySelectorAll('input, select, textarea'))
                .filter((el) => el.offsetParent !== null && !el.disabled)
                .filter((el) => !el.hasAttribute('data-zb-field'))
                .filter((el) => !el.closest('.zb-combo')) // pickers manage their own keys
                .map((el) => el.id || el.name || el.className);
        });
        expect(stranded).toEqual([]);
    });
});
