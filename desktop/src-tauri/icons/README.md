# App icons

These are **generated**, not committed. Before the first build, run (from `desktop/src-tauri`):

```bash
cargo tauri icon ../icon.png
```

That reads the branded source `desktop/icon.png` and writes every size/format the
bundler needs into this folder: `32x32.png`, `128x128.png`, `128x128@2x.png`,
`icon.icns` (macOS), `icon.ico` (Windows). These exact filenames are the ones
referenced by `tauri.conf.json → bundle.icon`.

(Replace `../icon.png` with your own 512×512+ square PNG to rebrand.)
