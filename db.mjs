import { chromium } from '@playwright/test';
const b = await chromium.launch();
const p = await b.newPage();
await p.goto('http://demo.tally.test/login');
await p.fill('#email','e2e@zerobook.test'); await p.fill('#password','e2e-playwright-local');
await p.click('button[type=submit], input[type=submit]'); await p.waitForURL(/\/app|\/companies/);
await p.goto('http://demo.tally.test/day-book');
await p.waitForFunction(() => window.Alpine?.store?.('zb'));
await p.waitForTimeout(600);
const info = await p.evaluate(() => {
  const out = [];
  document.querySelectorAll('[x-data]').forEach(e => {
    try { const d = window.Alpine.$data(e); out.push({ keys: Object.keys(d).slice(0,14), hasCfg: !!d.cfg }); } catch(_) {}
  });
  return out;
});
console.log(JSON.stringify(info, null, 1).slice(0, 900));
