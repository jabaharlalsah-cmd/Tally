import { defineConfig, devices } from '@playwright/test';

/**
 * ZeroBook browser tests.
 *
 * WebKit is not optional here — it is the whole point. The previous build was
 * rejected because shortcuts did not work on macOS and Safari, and WebKit is
 * Safari's engine. Section 6.3 of the build brief requires Chromium AND WebKit
 * as a minimum, with WebKit standing in for Safari on a real Mac.
 *
 * Served by Laragon's Apache at demo.tally.test, so there is no webServer block
 * — the vhost is already running. If Apache is down these tests fail fast with
 * a connection error rather than hanging.
 */
export default defineConfig({
    testDir: './tests/e2e',
    fullyParallel: false, // one tenant, shared session state
    forbidOnly: !!process.env.CI,
    retries: 0,
    workers: 1,
    reporter: [['list']],
    timeout: 30_000,
    use: {
        baseURL: process.env.ZB_E2E_URL || 'http://demo.tally.test',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
        {
            name: 'webkit',
            use: { ...devices['Desktop Safari'] },
        },
    ],
});
