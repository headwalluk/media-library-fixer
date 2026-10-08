# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Media Library Fixer is a WordPress utility plugin that resyncs the Media Library with the uploads
folder. It adds **Tools → Fix Media Library**, where each *module* runs a read-only scan, lists
what it found, and applies a fix to the items the user selects. Two modules ship:

- **Unregistered files** — files in uploads with no attachment record; the fix registers them in
  place, reading PDF metadata for the date, title and description
- **Missing files** — attachments whose file has gone; the fix deletes the attachment record

Other hygiene tasks are meant to arrive as modules through the `mlfixer_modules` filter, not as
core features. Keep the core lean.

- **Namespace:** `Media_Library_Fixer` for all classes and functions
- **Text Domain:** `media-library-fixer`
- **Prefix:** `mlfixer_` for options, hooks and template globals; `_mlfixer_` for post meta
- **PHP:** 8.2+, matching the maintainer's other plugins; nothing newer than 8.0 is used yet. Check
  any newer syntax against the floor before using it. No `declare(strict_types=1)`
- **WordPress:** 6.2+
- **No build system** — no npm, no Composer, no bundler. Assets are plain CSS/JS

The maintainer's reference plugin is Quick 2FA; conventions here follow it. Where this file is
silent, Quick 2FA's `CLAUDE.md` is the standard.

This plugin may be published. Tracked files must contain no client names, client URLs or client
data — in code, comments, docs, fixtures or commit messages. `dev-notes/` is **private and
untracked** (`.gitignore`), blocked from the web by `dev-notes/.htaccess`, and is the home for
client-identifying material. Never copy from it into a tracked file without scrubbing.

## Commands

```bash
phpcs                          # WordPress Coding Standards (phpcs.xml)
phpcbf                         # Auto-fix
node --check assets/admin/tool-page.js
```

## Testing

No unit-test framework, and none is wanted. Behaviour harnesses in `dev-notes/testing/` run
against a dev site with WP-CLI:

```bash
wp eval-file wp-content/plugins/media-library-fixer/dev-notes/testing/test-pdf-metadata-reader.php [list.txt]
wp eval-file wp-content/plugins/media-library-fixer/dev-notes/testing/test-modules.php
wp eval-file wp-content/plugins/media-library-fixer/dev-notes/testing/test-ajax-endpoint.php
```

- `test-pdf-metadata-reader.php` takes an optional file of uploads-relative PDF paths and
  compares every `CreationDate` with poppler's `pdfinfo`. Run it against a real corpus after any
  change to the reader
- `test-modules.php` builds fixtures in `uploads/1999/01` and removes them, and every attachment
  it creates, afterwards. It refuses to run if that folder exists
- Every harness restores the options it changes

**Rolling back test imports:** use `wp media-library-fixer undo-import` (pass IDs to leave other
people's imports alone). Never delete an imported attachment through the Media Library or
`wp post delete` on a site whose files matter — WordPress deletes the original file with it.
`Attachment_Importer::remove_import()` shields the original through `wp_delete_file`.

**`_wp_attached_file` comparisons in SQL ignore case** under the usual collations. Compare paths in
PHP with `===`; some sites hold files whose names differ only in case.

**PHP-FPM and WP-CLI can differ.** ImageMagick's security policy can allow PDFs in one and
not the other, so PDF previews must be checked from a browser run as well as from WP-CLI.

## Architecture

### Entry point

`media-library-fixer.php` → `media_library_fixer_run()` → `Plugin::instance()->run()`. Classes are
loaded with `require_once` in the main file; no autoloader. Admin classes are only constructed
under `is_admin()` (which includes admin-ajax).

### Modules and the job runner

- `Module` (abstract) — the extension contract: `get_scan_units()`, `scan_unit()`,
  `fix_finding()`, plus labels. A finding is an array with `key`, `label`, `detail`, optional
  `url` and module-private `data`
- `Job_Runner` — runs one module's scan or fix in steps of at most `get_batch_time_limit()`
  seconds (always at least one unit or item), keeping state in the non-autoloaded option
  `mlfixer_job_{module-id}`. Phases: `idle` → `scanning` → `scanned` → `fixing` → `scanned`.
  A stopped or interrupted run stays in its phase and resumes from its index
- `Tool_Page` — the admin page and its single AJAX action, `mlfixer_job`, with an `operation` of
  `status`, `scan_start`, `scan_step`, `fix_start`, `fix_step` or `reset`. Findings travel to the
  browser only in the `idle` and `scanned` phases, and never with their `data`
- `CLI_Commands` — the same runner from `wp media-library-fixer`, sharing saved results

The runner catches `\Throwable` from module code, logs it with `log_error()` and records it in the
job state (`scan_errors`, `failed`), so one bad unit or item never stops a run.

### Importing

`Attachment_Importer::import()` takes a path relative to uploads and refuses anything outside
uploads (`realpath` check), of a type WordPress doesn't allow, or already registered. It never
moves or copies the file. Date, title and description come from the settings and the document
properties; see `docs/how-it-works.md`.

`Pdf_Metadata_Reader` is a deliberately small reader, not a PDF parser: it indexes `N G obj`
markers, inflates object streams, follows the trailer's `/Info` (or an inline `/Info << >>`) and
the catalog's `/Metadata`. It skips Info strings in encrypted files. It must never warn or throw
on a malformed file — return empty values instead.

### Key Files

| File | Purpose |
|------|---------|
| `media-library-fixer.php` | Main file, constants, class loading, activation defaults |
| `constants.php` | Option and meta keys, choice values, phases, limits, defaults |
| `functions-private.php` | Settings getters, module registry, uploads helpers, PDF preview probe, `log()` / `log_error()` |
| `includes/class-module.php` | Abstract module contract |
| `includes/class-job-runner.php` | Batched scan/fix with persisted state |
| `includes/class-attachment-importer.php` | Creates an attachment for a file already in uploads |
| `includes/class-pdf-metadata-reader.php` | Info dictionary and XMP reader |
| `includes/modules/` | The two built-in modules |
| `includes/class-tool-page.php` | Tools page, asset loading, AJAX endpoint |
| `includes/class-settings.php` | Settings page and sanitisers |
| `includes/class-cli-commands.php` | `wp media-library-fixer` |
| `assets/admin/tool-page.js` | Page script, one `ModulePanel` per module |

### Data Storage

- Settings: `mlfixer_*` options. Job state: `mlfixer_job_{module-id}`, not autoloaded; it holds
  every finding, so it can reach a few MB on a large site
- Imported attachments carry `_mlfixer_imported` (Unix timestamp)
- Uninstall deletes nothing unless *Delete all plugin data when uninstalled* is on, and never
  deletes attachments

## Public Contracts

Treat these as contracts: filters and actions (`docs/developers/hooks-and-filters.md`), the
`Module` class and the finding shape, module IDs, stored key strings (`_mlfixer_imported`,
`mlfixer_job_` prefix), the script/style handles `media-library-fixer-tool`, and the WP-CLI
command names. Add, don't change; deprecate, don't rename; new filter arguments go at the end.
If you can't tell whether something outside the plugin depends on it, stop and ask.

### High-Impact Code

These paths create or delete records in bulk on client sites:

- `Attachment_Importer::import()` and its guards, including `is_registered()`
- `Attachment_Importer::remove_import()` — must never delete the original file
- `Missing_Files_Module::fix_finding()`, which calls `wp_delete_attachment()`
- `Job_Runner::start_fix()` and `fix_step()`
- `Tool_Page::handle_ajax()` — nonce and capability checks

When a change touches one, say so, run every harness, and recommend the maintainer read that diff
line by line.

## Code Conventions

Follow Quick 2FA's `CLAUDE.md` in full. The rules that come up most here:

- **Single entry, single exit.** Guard clauses at the top are fine; no `return` inside a loop
  (set, `break`, return at the end)
- **An `if` with `elseif` ends in `else`**, a no-op branch holding a short comment
- **No assignment in a condition.** Constants for every key and magic value
- **Callbacks on hooks the plugin doesn't own take `mixed`.** Check what a filter returns and fall
  back to the default
- **Never swallow an error.** Every `catch` logs with `log_error()` and records the failure where
  the user will see it (job state, import message). `log()` is debug-only flow tracing
- **Templates are code-first** — `printf()`/`echo`, no inline HTML. Template globals are
  prefixed `mlfixer_`
- **CSS uses logical properties.** JS is plain, no jQuery; text goes in with `textContent`
- Comments say how the code works; rationale goes in `docs/`
- Every `phpcs:ignore` names the sniff and ends with `-- reason`

### Commit Messages

`type: brief description` — `feat:`, `fix:`, `chore:`, `refactor:`, `docs:`, `style:`, `test:`.

## Release Workflow

1. Bump `Version:` and `MEDIA_LIBRARY_FIXER_VERSION` in `media-library-fixer.php`, and the
   `readme.txt` stable tag — all three must agree
2. Move `[Unreleased]` in `CHANGELOG.md` under the version
3. `phpcs` clean, every harness passing
4. Tag

## Reference Files

`docs/` is public, one audience per file:

- `docs/how-it-works.md`, `docs/configuration.md`, `docs/wp-cli.md` — site operators
- `docs/developers/hooks-and-filters.md` — the public extension surface
- `docs/developers/writing-a-module.md` — adding a module

Private, untracked:

- `dev-notes/03-working-notes.md` — **read first in a new session**: environment, state, testing
  tools, lessons learned. Written because Claude Code's memory doesn't follow the repo between
  paths
- `dev-notes/testing/browser/` — headless-Chromium tests of the tool page (`run.sh`)
- `dev-notes/00-project-tracker.md` — client context, the survey behind the defaults, decisions,
  candidate modules
- `dev-notes/02-snagging-list.md` — small agreed items. Check it before starting work
