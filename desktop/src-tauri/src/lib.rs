// ZeroBook Desktop (Phase 7C) — the native shell.
//
// Responsibilities the webview genuinely can't do itself:
//   • own the window (size/position remembered, no browser chrome);
//   • inject `window.ZB_DESKTOP = true` + the sync bridge into every page (so the
//     SAME ZeroBook web app, loaded from the tenant subdomain, knows it is running
//     desktop-side and captures the browser-reserved keys);
//   • a native File/Edit/View/Help menu;
//   • SECURE session-token storage in the OS keychain (never a plaintext file);
//   • the auto-updater client;
//   • a local SQLite database (the sync mirror / outbox / inbox).
//
// It contains NO accounting logic — the server stays authoritative.

use tauri::menu::{Menu, MenuItem, PredefinedMenuItem, Submenu};
use tauri::{Emitter, Manager, WebviewUrl, WebviewWindowBuilder};
use tauri_plugin_sql::{Migration, MigrationKind};

/// Injected before every page loads (local shell AND the remote tenant app). Sets
/// the desktop flag the keyboard engine consults, then boots the sync bridge.
const ZB_INIT_FLAG: &str = r#"window.ZB_DESKTOP = true; window.__ZB_DESKTOP_VERSION__ = "1.0.0";"#;

/// The sync bridge + worker, embedded at compile time so it runs inside the remote
/// tenant page (which we can't otherwise ship code to).
const ZB_INJECT: &str = include_str!("../../dist/desktop-inject.js");

const KEYRING_SERVICE: &str = "in.zerobook.desktop";

#[tauri::command]
fn app_version() -> String {
    env!("CARGO_PKG_VERSION").to_string()
}

/// Store the tenant session token in the OS keychain (Windows Credential Manager /
/// macOS Keychain / Secret Service), keyed by tenant subdomain.
#[tauri::command]
fn store_token(account: String, token: String) -> Result<(), String> {
    keyring::Entry::new(KEYRING_SERVICE, &account)
        .map_err(|e| e.to_string())?
        .set_password(&token)
        .map_err(|e| e.to_string())
}

#[tauri::command]
fn get_token(account: String) -> Result<Option<String>, String> {
    let entry = keyring::Entry::new(KEYRING_SERVICE, &account).map_err(|e| e.to_string())?;
    match entry.get_password() {
        Ok(p) => Ok(Some(p)),
        Err(keyring::Error::NoEntry) => Ok(None),
        Err(e) => Err(e.to_string()),
    }
}

#[tauri::command]
fn clear_token(account: String) -> Result<(), String> {
    let entry = keyring::Entry::new(KEYRING_SERVICE, &account).map_err(|e| e.to_string())?;
    match entry.delete_credential() {
        Ok(_) | Err(keyring::Error::NoEntry) => Ok(()),
        Err(e) => Err(e.to_string()),
    }
}

fn build_menu(app: &tauri::AppHandle) -> tauri::Result<Menu<tauri::Wry>> {
    let signout = MenuItem::with_id(app, "signout", "Sign Out", true, None::<&str>)?;
    let file = Submenu::with_items(
        app,
        "File",
        true,
        &[
            &signout,
            &PredefinedMenuItem::separator(app)?,
            &PredefinedMenuItem::quit(app, Some("Quit ZeroBook"))?,
        ],
    )?;

    let edit = Submenu::with_items(
        app,
        "Edit",
        true,
        &[
            &PredefinedMenuItem::undo(app, None)?,
            &PredefinedMenuItem::redo(app, None)?,
            &PredefinedMenuItem::separator(app)?,
            &PredefinedMenuItem::cut(app, None)?,
            &PredefinedMenuItem::copy(app, None)?,
            &PredefinedMenuItem::paste(app, None)?,
            &PredefinedMenuItem::select_all(app, None)?,
        ],
    )?;

    let reload = MenuItem::with_id(app, "reload", "Reload", true, Some("CmdOrCtrl+Shift+R"))?;
    let fullscreen = MenuItem::with_id(app, "fullscreen", "Toggle Full Screen", true, Some("Ctrl+Shift+F"))?;
    let view = Submenu::with_items(app, "View", true, &[&reload, &fullscreen])?;

    let about = MenuItem::with_id(app, "about", "About ZeroBook Desktop", true, None::<&str>)?;
    let checkupd = MenuItem::with_id(app, "checkupdate", "Check for Updates…", true, None::<&str>)?;
    let help = Submenu::with_items(app, "Help", true, &[&about, &checkupd])?;

    Menu::with_items(app, &[&file, &edit, &view, &help])
}

#[cfg_attr(mobile, tauri::mobile_entry_point)]
pub fn run() {
    // Local SQLite: the sync outbox / inbox / mirror. Lives in the app-data dir.
    let migrations = vec![Migration {
        version: 1,
        description: "sync outbox + inbox + local mirror",
        sql: include_str!("../migrations/001_sync.sql"),
        kind: MigrationKind::Up,
    }];

    tauri::Builder::default()
        .plugin(tauri_plugin_window_state::Builder::default().build())
        .plugin(tauri_plugin_dialog::init())
        .plugin(tauri_plugin_process::init())
        .plugin(tauri_plugin_updater::Builder::new().build())
        .plugin(
            tauri_plugin_sql::Builder::default()
                .add_migrations("sqlite:zerobook_local.db", migrations)
                .build(),
        )
        .invoke_handler(tauri::generate_handler![app_version, store_token, get_token, clear_token])
        .setup(|app| {
            let init = format!("{ZB_INIT_FLAG}\n{ZB_INJECT}");

            WebviewWindowBuilder::new(app, "main", WebviewUrl::App("index.html".into()))
                .title("ZeroBook Desktop")
                .inner_size(1280.0, 800.0)
                .min_inner_size(1024.0, 640.0)
                .center()
                .initialization_script(&init)
                .build()?;

            app.set_menu(build_menu(app.handle())?)?;
            Ok(())
        })
        .on_menu_event(|app, event| {
            let id = event.id().0.as_str();
            match id {
                // Handled in the webview (branded UI / uses the injected bridge).
                "signout" => {
                    let _ = app.emit("zb://menu/signout", ());
                }
                "about" => {
                    let _ = app.emit("zb://menu/about", ());
                }
                "checkupdate" => {
                    let _ = app.emit("zb://menu/check-update", ());
                }
                // Native window operations.
                "reload" => {
                    if let Some(w) = app.get_webview_window("main") {
                        let _ = w.eval("window.location.reload()");
                    }
                }
                "fullscreen" => {
                    if let Some(w) = app.get_webview_window("main") {
                        let now = w.is_fullscreen().unwrap_or(false);
                        let _ = w.set_fullscreen(!now);
                    }
                }
                _ => {}
            }
        })
        .run(tauri::generate_context!())
        .expect("error while running ZeroBook Desktop");
}
