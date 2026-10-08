# How it works

Media Library Fixer adds **Tools → Fix Media Library**. The page has one panel per *module*.
Each module does two things:

1. **Scan** — reads the uploads folder and the database and lists what it finds. A scan never
   changes anything
2. **Fix** — applies the module's fix to the items you tick, and only those

Both run in short batches (about eight seconds each) so a large site never hits a PHP or proxy
timeout. A progress bar shows how far through the current run you are. **Stop** finishes the
current batch and stops; reloading the page or closing the tab does the same. Next time the page
loads it offers to **Continue** from where the run stopped.

Scan results are saved, so you can scan today and fix tomorrow, or scan in the browser and fix
from WP-CLI. Each module keeps the results of its own last scan; **Clear results** forgets them.

Everything needs the `manage_options` capability (administrators).

## Files missing from the Media Library

Lists files in the uploads folder that no attachment points at, limited to the file types in
[settings](configuration.md#file-types) (`pdf` by default).

A file counts as *known* when an attachment's file, one of its resized copies, its pre-scaled
original or one of its edit backups has that name. Images WordPress generated from another file in
the same folder are skipped even when nothing records them: resized (`photo-300x200.jpg`), scaled
(`-scaled`), rotated (`-rotated`), edited (`-e1700000000000`) and PDF previews (`report-pdf.jpg`).
Only images are checked for this, and only when the file they were made from is present, so a PDF
named `plan-1200x800.pdf` or an orphaned `lone-150x150.jpg` is still listed.

**Importing** creates an attachment record that points at the file where it is. The file is not
moved, copied or renamed, so its URL doesn't change and existing links keep working. Each import is
re-checked first: a file that has gone or has been registered since the scan is reported as a
failure and skipped. Names are compared exactly, so `Report.pdf` and `report.pdf` in the same folder
are two files.

Imported attachments:

- are *unattached* (not linked to any post)
- carry an `_mlfixer_imported` marker so they can be found later
- go through WordPress's normal metadata step, so plugins that process uploads (image optimisers,
  search indexers) see them as they would an upload

> **Removing an imported item from the Media Library deletes the file too.** That is WordPress's
> behaviour for every attachment, and it is the opposite of the import, which never touched the
> file. To reverse an import, use [`wp media-library-fixer undo-import`](wp-cli.md), which deletes
> the records and keeps the files. Take a backup before a bulk import all the same.

### The date

WordPress shows the attachment's *upload date*. A file copied from another server usually has the
copy's date as its modified time, so every file from a migration would otherwise appear to be
uploaded the same week. The **Date** setting chooses the source, and each source falls back to the
next when it has nothing:

1. **Document properties** — the creation date stored inside a PDF
2. **Upload folder** — noon on the first of the month of the `YYYY/MM` folder the file is in
3. **File modified time**

The import log says which source each date came from.

### The title and description

The title is the file name, with underscores turned into spaces (hyphens are kept, as in
`COVID-19`). With **Title** set to use the document's own title, a PDF's embedded title is used
instead where it looks genuine: titles with fewer than two words, ending in a file extension, or
left by an authoring tool (`Microsoft Word - …`, `Layout 1`, `Untitled`) are ignored.

With **Description** on, the document's author, subject and keywords (and its embedded title, if
genuine and not already the title) are written into the description, where the Media Library
search finds them.

### Reading PDF metadata

The plugin reads PDFs itself; it needs no extension, library or command-line tool. It reads the
document information dictionary, including when it is compressed inside an object stream (as it is
in most PDFs written since about 2010), and falls back to the XMP metadata stream.

It can't read the properties of a PDF encrypted with a password or with permissions set
(no printing, no copying): those are scrambled. Such files fall back to the folder date. Files over
64 MB aren't read at all; see `mlfixer_pdf_max_bytes`.

A PDF date with no time zone is read as the site's time zone (Settings → General).

### PDF previews

When WordPress can, it makes preview images of an uploaded PDF, shown in the Media Library grid.
The importer does the same with **PDF previews** on (it is off by default). That needs ImageMagick with Ghostscript, and
many servers block PDFs in ImageMagick's security policy. The settings page says whether the server
can make them, and the import log says when one wasn't made.

The policy can differ between the web server's PHP and the command-line PHP that runs WP-CLI. If
previews fail from the browser, try the import with [WP-CLI](wp-cli.md). Run WP-CLI as the web
server's user, or the preview images will be owned by your own account.

## Media Library items with missing files

Checks every attachment for its file on disk. An attachment with no file recorded at all is listed
too. Attachments whose files live on remote storage through an offload plugin are skipped.

**Deleting** removes the attachment record, its metadata and any resized copies still on disk.
Pages that link to or embed the item will show a broken link or image, so check the list first: a
file can go missing because a folder wasn't copied, and copying it back is often the better fix.
Each deletion is re-checked first: if the file is back, the record is kept and the log says so.

Deleting asks for confirmation and can't be undone.

## Deactivating and deleting the plugin

Deactivating changes nothing. Deleting the plugin from the Plugins screen keeps its settings,
saved scan results and the `_mlfixer_imported` markers, unless **Delete all plugin data when
uninstalled** is ticked. Imported attachments are never deleted.
