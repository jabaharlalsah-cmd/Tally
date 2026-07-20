# ZeroBook — keyboard shortcut reference

**Target:** TallyPrime 7.x behaviour, adapted for the browser.
**Last verified:** 2026-07-20 (Phase 1), against `resources/js/engine/engine.js`.

> The authoritative scheme is `New Account Software/_docs/keyboard-shortcuts.md`.
> Where that document and this one disagree, **that document wins**. This file records
> what ZeroBook actually binds today, including the inventory vouchers that are out of
> scope for that project but in scope here.

You can see this same list inside the app at any time by pressing **F1**. The in-app
version is generated from the engine's own registry, so it can never drift from reality.

---

## How modifiers are shown

| You are on | The app shows | You press |
|---|---|---|
| Windows / Linux | `Ctrl` `Alt` `Shift` | Ctrl, Alt, Shift |
| macOS | `⌘` `⌥` `⇧` | Command, Option, Shift |

On a Mac, **Command does the job of Ctrl**. ⌘A accepts a voucher exactly as Ctrl+A does
on Windows. You do not need to learn a second set of keys.

macOS keeps a handful of Command combos for itself, and ZeroBook deliberately does not
fight them: **⌘Q ⌘W ⌘H ⌘M ⌘N ⌘T ⌘R ⌘Space ⌘Tab ⌘, ⌘` ⌘[ ⌘]** all behave exactly as they
do in any other Mac app.

---

## Anywhere in the app

| Key | Action |
|---|---|
| `F1` | Keyboard help (this list, live) |
| `F2` | Change date |
| `Alt+F2` | Change period |
| `F3` | Select company |
| `F11` | Company features |
| `F12` | Configure screen |
| `Alt+G` | Go To — jump to any screen by name |
| `Ctrl+N` | Calculator |
| `Ctrl+Alt+N` | Calculator (alternate — see *Keys your browser keeps*) |
| `Ctrl+A` | Accept and save the current screen |
| `Enter` | Next field · drill down on a report line |
| `Backspace` | Previous field (when the field has nothing left to erase) |
| `Esc` | Back one level |

## Accounting vouchers

| Key | Voucher |
|---|---|
| `F4` | Contra |
| `F5` | Payment |
| `F6` | Receipt |
| `F7` | Journal |
| `F8` | Sales |
| `F9` | Purchase |
| `Alt+F6` | Credit Note |
| `Alt+F5` | Debit Note |

## Inventory vouchers

| Key | Voucher |
|---|---|
| `Alt+F7` | Stock Journal |
| `Ctrl+F7` | Physical Stock |
| `Alt+F8` | Delivery Note |
| `Alt+F9` | Receipt Note |
| `Ctrl+F8` | Sales Order |
| `Ctrl+F9` | Purchase Order |
| `Ctrl+F5` | Rejections Out |
| `Ctrl+F6` | Rejections In |

---

## Changed in Phase 1 (2026-07-20)

Eight keys were corrected to match TallyPrime. Several had been using a key that Tally
assigns to a *different* voucher, so a Tally operator pressing `Alt+F6` for a Credit Note
would previously have got a Sales Order.

| Action | Was | Now |
|---|---|---|
| Credit Note | `Ctrl+F8` | `Alt+F6` |
| Debit Note | `Ctrl+F9` | `Alt+F5` |
| Receipt Note | `Alt+F5` | `Alt+F9` |
| Sales Order | `Alt+F6` | `Ctrl+F8` |
| Purchase Order | `Alt+F7` | `Ctrl+F9` |
| Rejections Out | `Ctrl+F6` | `Ctrl+F5` |
| Rejections In | `Ctrl+F5` | `Ctrl+F6` |
| Select company | `F1` | `F3` |
| Calculator (alternate) | `Alt+N` | `Ctrl+Alt+N` |

`F1` is now Help, as in TallyPrime. Stock Journal (`Alt+F7`) and Physical Stock
(`Ctrl+F7`) previously had no shortcut at all and now do.

---

## Keys your browser keeps

A browser tab cannot take every key — the browser claims some before any web page sees
them. ZeroBook handles this three ways, in descending order of fidelity:

1. **ZeroBook Desktop** — no browser chrome at all. Every key works.
2. **Installed as an app** (recommended) — no address bar, no tab strip, so `F11`, `F12`,
   `Ctrl+T` and `Alt+D` come back to ZeroBook. Press `F1` and use the **Install** button.
   On Safari: *File ▸ Add to Dock* (Mac) or *Share ▸ Add to Home Screen* (iPad).
3. **A plain browser tab** — these alternates:

| Key | The browser uses it for | Press instead |
|---|---|---|
| `F11` | Full screen | `Alt+F11`, or the Features button |
| `F12` | Developer tools | `Alt+F12`, or the Configure button |
| `Ctrl+N` | New window | `Ctrl+Alt+N`, or the calculator button |
| `F6` | Focus the address bar | `Alt+G` (Go To) → Receipt |

Firefox desktop cannot install web apps, so its reserved keys stay reserved there.

---

## Safeguards

- **`F5` never reloads the page.** It is Tally's Payment key and the most-pressed key in
  the app; a reload mid-entry would discard the voucher. The browser's reload is
  suppressed and the Payment voucher opens instead.
- **`Ctrl+R` never reloads either**, for the same reason. On a Mac, `⌘R` is left to macOS
  as users expect — an unsaved-work prompt covers that path instead.
- **Holding a key repeats navigation only.** Holding `F5` will not open twenty vouchers.
- **Typing is never stolen.** Shortcuts stand down for input methods (including Indic and
  CJK composition), dead keys, and AltGr characters on European layouts. `⌘C` `⌘V` `⌘X`
  `⌘Z` stay native inside fields.

---

## Testing

| Layer | Command | Covers |
|---|---|---|
| Key mapping | `npm test` | 43 cases, Windows map + macOS map |
| Real browsers | `npm run test:e2e` | 26 cases on Chromium **and WebKit** (Safari's engine) |
| WebKit only | `npm run test:e2e:webkit` | the Safari proxy on its own |

The unit suite is verified non-vacuous by mutation: reintroducing the original macOS
defect fails it.
