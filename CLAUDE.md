# tally

Project notes for Claude Code.

---

## Standing rules for this project

These apply to every task in this project, without being asked.

- Follow the `project-folder-sync` skill on every task: **QA/QC first** â€” the new work must actually run, and nothing that already worked may be broken. Only after that check passes, commit.
- Commit on a **work branch** only. Never commit to the live/deploy branch, never `git push`, never deploy. Merging to live is a manual step the owner does.
- Follow the `dibitech-ui-coding-standards` skill for all UI and code style.
- Deliver complete, ready-to-use files â€” never snippets or partial diffs.
- When a database table or schema changes, provide a ready-to-run phpMyAdmin SQL script alongside the migration.
- Explain everything in simple, plain language.

<!-- DIBI-STANDING-RULES:START v2 -->
## Standing rules for this project

- Follow the project-folder-sync skill on every task. These rules apply to every
  project, every language, every size of change - no project is exempt.
- Build the WHOLE feature, never backend-only: list page, add form, edit form,
  view/detail where useful, delete, validation messages, success/error messages,
  and a working link in the menu. Every column in the table must appear on a form
  or be a clearly system-filled field. No dead buttons, no empty dropdowns, no
  placeholder links, no page that nothing links to.
- Test like a real human before saying done: open the feature from the menu (not
  by typing the URL), submit the empty form to see the validation messages, fill
  every field with realistic data, save, confirm every field really landed in the
  database, edit it, save again, delete it, and try wrong input to check the error
  messages. Check the browser console, the network tab and the server log are
  clean. Write down which screens were actually walked through.
- Then QA/QC the rest: nothing that already worked is broken, edge cases and
  security considered.
- Then commit on a work branch, automatically, as soon as the change is finished.
  Never commit to the live/deploy branch, never push, never deploy.
- Follow the dibitech-ui-coding-standards skill for all UI and code style.
- Deliver complete, ready-to-use files - never snippets or partial diffs.
- When a database table or schema changes, provide a ready-to-run phpMyAdmin SQL
  script alongside the migration.
- Explain everything in simple, plain language.
- Never say READY when a screen is missing, a walkthrough step failed, or the
  click-through could not be run. Say NOT CLICK-TESTED instead.
<!-- DIBI-STANDING-RULES:END -->
