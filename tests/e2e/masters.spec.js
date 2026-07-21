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

        // Wait for focus to actually MOVE after each Enter rather than firing a
        // fixed number of presses back to back. Livewire re-renders between
        // fields, and a press that lands mid-render is swallowed — which made
        // this test fail only when both browser projects ran together, i.e. the
        // worst kind of flake: green in isolation, red under load.
        const focusedId = () => page.evaluate(() => document.activeElement?.id || '');
        for (let i = 0; i < chain.length + 4; i++) {
            const before = await focusedId();
            if (before === 'l-opening') break;
            await page.keyboard.press('Enter');
            await expect
                .poll(focusedId, { timeout: 3000 })
                .not.toBe(before);
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

test.describe('Retire / restore a master', () => {
    test('a retired ledger disappears from pickers, and comes back on restore', async ({ page }) => {
        await login(page);

        // Find a non-reserved ledger — reserved ones cannot be retired.
        await page.goto('/masters/ledgers?mode=display');
        await page.waitForFunction(() => window.Alpine?.store?.('zb'));
        const target = await page.evaluate(() => {
            const items = window.Alpine.store('masters').ledgers || [];
            const l = items.find((x) => !x.is_reserved && x.is_active !== false);
            return l ? { id: l.id, name: l.name } : null;
        });
        test.skip(!target, 'no non-reserved ledger in this company');

        const inPicker = () =>
            page.evaluate((name) => {
                const items = window.Alpine.store('masters').ledgers || [];
                const l = items.find((x) => x.name === name);
                return l ? l.is_active !== false : null;
            }, target.name);

        expect(await inPicker()).toBe(true);

        // Retire it through the real Livewire action.
        const retired = await page.evaluate(async (id) => {
            const el = document.querySelector('.zb-ws');
            return await window.Alpine.$data(el).$wire.saveActiveState(id, false);
        }, target.id);
        expect(retired.ok).toBe(true);

        // Reload so the picker payload is rebuilt server-side.
        await page.goto('/masters/ledgers?mode=display');
        await page.waitForFunction(() => window.Alpine?.store?.('zb'));
        expect(await inPicker()).toBe(false);

        // Restore, and confirm it returns.
        await page.evaluate(async (id) => {
            const el = document.querySelector('.zb-ws');
            return await window.Alpine.$data(el).$wire.saveActiveState(id, true);
        }, target.id);
        await page.goto('/masters/ledgers?mode=display');
        await page.waitForFunction(() => window.Alpine?.store?.('zb'));
        expect(await inPicker()).toBe(true);
    });

    test('a reserved ledger refuses to be retired', async ({ page }) => {
        await login(page);
        await page.goto('/masters/ledgers?mode=display');
        await page.waitForFunction(() => window.Alpine?.store?.('zb'));
        const reserved = await page.evaluate(() => {
            const items = window.Alpine.store('masters').ledgers || [];
            const l = items.find((x) => x.is_reserved);
            return l ? l.id : null;
        });
        test.skip(!reserved, 'no reserved ledger seeded');

        const res = await page.evaluate(async (id) => {
            const el = document.querySelector('.zb-ws');
            return await window.Alpine.$data(el).$wire.saveActiveState(id, false);
        }, reserved);
        expect(res.ok).toBe(false);
        expect(res.message).toContain('Reserved');
    });
});

test.describe('Manage gear beside master dropdowns', () => {
    test('the gear appears beside the Under picker and links to the group manager', async ({ page }) => {
        await ledgerCreateForm(page);
        const gear = page.locator('form[data-zb-form="ledger"] .zb-manage-gear').first();
        await expect(gear).toBeVisible();
        await expect(gear).toHaveAttribute('title', /Manage/);
    });

    test('the gear is NOT in the Enter chain', async ({ page }) => {
        await ledgerCreateForm(page);
        // It is a mouse affordance. If Enter walked onto it, every operator
        // filling the form by keyboard would land on a button that navigates.
        const tabindex = await page
            .locator('form[data-zb-form="ledger"] .zb-manage-gear')
            .first()
            .getAttribute('tabindex');
        expect(tabindex).toBe('-1');
    });

    test('clicking the gear on an EMPTY form navigates without prompting', async ({ page }) => {
        await ledgerCreateForm(page);
        await page.locator('form[data-zb-form="ledger"] .zb-manage-gear').first().click();
        await page.waitForURL(/masters\/groups/, { timeout: 10_000 });
    });

    test('clicking the gear with unsaved input WARNS before discarding it', async ({ page }) => {
        await ledgerCreateForm(page);
        await page.fill('#l-name', 'Half typed ledger');

        await page.locator('form[data-zb-form="ledger"] .zb-manage-gear').first().click();

        // Tally's own Accept? gate, not a browser dialog.
        const prompt = page.locator('.zb-accept, [x-show*="accept.open"]').first();
        await expect(prompt).toBeVisible({ timeout: 5000 });
        await expect(page.locator('body')).toContainText(/not been saved|discard/i);

        // Still on the ledger screen — nothing lost yet.
        expect(page.url()).toContain('/masters/ledgers');
    });

    test('quick-create modals carry no gear', async ({ page }) => {
        await ledgerCreateForm(page);
        // Alt+C on the Under picker opens the inline group create. The picker
        // input carries no id — master-select renders it by class.
        await page.locator('form[data-zb-form="ledger"] .zb-combo-input').first().click();
        await page.keyboard.press('Alt+c');
        const modal = page.locator('.zb-subscreen');
        await expect(modal).toBeVisible({ timeout: 5000 });
        // Navigating away from here would destroy the modal AND the ledger
        // behind it, so the gear is deliberately absent.
        await expect(modal.locator('.zb-manage-gear')).toHaveCount(0);
    });
});
