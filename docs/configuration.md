# Configuration

**Settings → Media Library Fixer.** The labels below are the settings page's own.

## Scanning

### File types

Comma-separated extensions the *Files missing from the Media Library* scan looks for. Default:
`pdf`. Only types WordPress allows in the Media Library are accepted; anything else is removed when
you save, with a notice saying so.

Adding image types (`jpg`, `png`, `webp`) works, and resized copies are recognised and skipped (see
[how it works](how-it-works.md#files-missing-from-the-media-library)). Importing an image makes
WordPress generate its resized copies, which is slower and writes files.

### Folders to scan

- **Year/month folders only** (default) — `uploads/2021/10` and the like. This is where WordPress
  files uploads unless *Organize my uploads into month- and year-based folders* is off under
  Settings → Media
- **The whole uploads folder** — every folder, skipping ones other plugins keep caches, logs, form
  submissions and backups in (`cache`, `elementor`, `wpcf7_uploads`, `wc-logs`, `backups`, `updraft`
  and others; see `mlfixer_excluded_directories`). Use this when uploads aren't in dated folders,
  and check the results before importing: plugins keep files in uploads that aren't meant to be
  Media Library items

## Importing

### Date

Sets the upload date of imported items. Each choice falls back to the next one down:

| Choice | Date used |
|---|---|
| **Document creation date** (default) | The creation date inside a PDF |
| **The upload folder** | Noon on the first of the month of the `YYYY/MM` folder |
| **The file's modified time** | The file's modified time on disk |

A migration usually resets every modified time to the day of the copy, so the last choice is rarely
right for older files.

### Title

- **The file name** (default) — underscores become spaces
- **The document's own title where it looks genuine** — see
  [how it works](how-it-works.md#the-title-and-description) for what counts as genuine

### Description

**Add the document's author, subject and keywords to the description.** On by default.

### PDF previews

**Generate preview images for imported PDFs.** Off by default: without previews an import only
writes database records, which takes a few milliseconds per file. The description under the setting
says whether this server can make them from the web.

## Uninstall

**Delete all plugin data when uninstalled.** Off by default. When ticked, deleting the plugin also
deletes its settings, saved scan results and the `_mlfixer_imported` markers. Imported attachments
are always kept.
