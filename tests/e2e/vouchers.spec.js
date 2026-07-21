/**
 * ZeroBook voucher entry — the unsaved-work guard.
 *
 * The voucher screen previously had NO protection of any kind. Three separate
 * paths discarded an in-progress voucher silently:
 *   - reload / tab close (no beforeunload)
 *   - Esc (navigated away unconditionally)
 *   - F4–F9 type switch (resetLines + cleared narration)
 *
 * TallyPrime asks before abandoning a voucher that has been typed into, and
 * these lock that behaviour in.
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

async function paymentVoucher(page) {
    await login(page);
    await page.goto('/vouchers/create/payment');
    await page.waitForFunction(() => window.ZB_VOUCHER);
    await expect(page.locator('.zb-voucher')).toBeVisible();
}

const isDirty = (page) => page.evaluate(() => window.ZB_VOUCHER.hasUnsavedWork());
const promptOpen = (page) => page.evaluate(() => window.Alpine.store('zb').accept.open);
const voucherType = (page) => page.evaluate(() => window.ZB_VOUCHER.type);

/** Type into the first real entry field, as an operator would. */
async function typeSomething(page, text = '123') {
    await page.evaluate(() => {
        const el = document.querySelector('.zb-voucher input[data-zb-field]');
        if (el) el.focus();
    });
    await page.keyboard.type(text);
    await expect.poll(() => isDirty(page)).toBe(true);
}

test.describe('Voucher unsaved-work guard', () => {
    test('a freshly opened voucher is not considered dirty', async ({ page }) => {
        await paymentVoucher(page);
        // Livewire hydrates fields after render and several carry defaults, so a
        // naive probe would report dirty here and cry wolf on every Esc.
        expect(await isDirty(page)).toBe(false);
    });

    test('typing marks it dirty', async ({ page }) => {
        await paymentVoucher(page);
        await typeSomething(page);
        expect(await isDirty(page)).toBe(true);
    });

    test('Esc on a clean voucher leaves immediately', async ({ page }) => {
        await paymentVoucher(page);
        await page.keyboard.press('Escape');
        await page.waitForURL(/\/app$/, { timeout: 10_000 });
    });

    test('Esc on a typed voucher asks before discarding it', async ({ page }) => {
        await paymentVoucher(page);
        await typeSomething(page);

        const before = page.url();
        await page.keyboard.press('Escape');
        await expect.poll(() => promptOpen(page)).toBe(true);
        // Nothing lost yet — still on the voucher.
        expect(page.url()).toBe(before);
    });

    test('switching voucher type on a CLEAN voucher does not prompt', async ({ page }) => {
        await paymentVoucher(page);
        await page.keyboard.press('F8'); // Sales
        await expect.poll(() => voucherType(page)).toBe('sales');
        expect(await promptOpen(page)).toBe(false);
    });

    test('switching type on a TYPED voucher asks first, and only then discards', async ({ page }) => {
        await paymentVoucher(page);
        await page.keyboard.press('F8'); // start on Sales
        await expect.poll(() => voucherType(page)).toBe('sales');

        await typeSomething(page);
        await page.keyboard.press('F5'); // Payment

        await expect.poll(() => promptOpen(page)).toBe(true);
        // The switch has NOT happened yet — this is the whole point. Previously
        // it reset the lines and cleared the narration with no warning at all.
        expect(await voucherType(page)).toBe('sales');

        await page.keyboard.press('Enter'); // answer Yes
        await expect.poll(() => voucherType(page)).toBe('payment');
        // Agreeing to discard also clears the flag, so the next Esc is silent.
        await expect.poll(() => isDirty(page)).toBe(false);
    });

    test('answering No keeps the voucher exactly as it was', async ({ page }) => {
        await paymentVoucher(page);
        await typeSomething(page);
        await page.keyboard.press('F8');
        await expect.poll(() => promptOpen(page)).toBe(true);

        await page.keyboard.press('Escape'); // dismiss the prompt = No
        await expect.poll(() => promptOpen(page)).toBe(false);
        expect(await voucherType(page)).toBe('payment');
        expect(await isDirty(page)).toBe(true);
    });
});
