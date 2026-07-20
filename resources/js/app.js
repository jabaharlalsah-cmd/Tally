/* =========================================================================
   ZeroBook — front-end entry (bundled by Vite)
   Alpine ships with Livewire 4 and auto-starts; we only register our store,
   components, and the global keyboard dispatcher on `alpine:init`.
   ========================================================================= */

import { zbStore, attachDispatcher } from './engine/engine.js';
import { registerComponents } from './engine/components.js';
import { registerHarness } from './harness.js';
import { registerMastersStore } from './masters/store.js';
import { registerSelect } from './masters/select.js';
import { registerWorkspace } from './masters/workspace.js';
import { registerCostCentre } from './masters/costcentre.js';
import { registerTdsSections } from './masters/tdssection.js';
import { registerInventory } from './masters/inventory.js';
import { registerVouchers } from './vouchers/screen.js';
import { registerReports } from './reports/screen.js';
import { registerBudgets } from './budgets/screens.js';
import { registerRatios } from './ratios/screens.js';
import { registerScenarios } from './scenarios/screens.js';
import { registerConfig } from './config/store.js';

document.addEventListener('alpine:init', () => {
    const Alpine = window.Alpine;
    Alpine.store('zb', zbStore());
    Alpine.store('zb').boot(window.ZB_CONFIG || {});
    registerComponents(Alpine);
    registerHarness(Alpine);

    // Phase 2 — Masters
    registerMastersStore(Alpine);
    registerSelect(Alpine);
    registerWorkspace(Alpine);
    registerCostCentre(Alpine);
    registerTdsSections(Alpine); // Phase 10A — the TDS rate table master
    registerInventory(Alpine);

    // Phase 3 — Vouchers
    registerVouchers(Alpine);

    // Phase 4 — Reports + F12 configuration
    registerReports(Alpine);
    registerConfig(Alpine);

    // Phase 15A — Budgets screens
    registerBudgets(Alpine);

    // Phase 15B — Ratio Analysis screens
    registerRatios(Alpine);

    // Phase 15C — Scenarios screens
    registerScenarios(Alpine);
});

// Attach the single global keydown dispatcher immediately (reads the store
// lazily once Alpine has initialised it).
attachDispatcher();
