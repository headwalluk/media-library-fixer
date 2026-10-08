# WP-CLI reference

The commands run the same modules as Tools → Fix Media Library and share their saved scan results,
so you can scan in one and fix in the other. Run them as the web server's user (for example
`sudo -u www-data wp …`), so files they write — PDF previews, resized images — have the right owner.

## `wp media-library-fixer modules`

Lists the modules with the phase and finding count of their last scan.

```bash
$ wp media-library-fixer modules
id                  title                                    phase    findings
unregistered-files  Files missing from the Media Library     scanned  2365
missing-files       Media Library items with missing files   idle     0
```

## `wp media-library-fixer scan <module> [--format=<format>]`

Runs a full scan and prints the findings. `--format` is `table` (default), `csv`, `json` or
`count`. The results are saved, replacing the previous scan.

```bash
$ wp media-library-fixer scan unregistered-files --format=count
2365

$ wp media-library-fixer scan unregistered-files --format=csv > unregistered.csv
```

## `wp media-library-fixer fix <module> [<key>...] [--limit=<number>] [--yes]`

Applies the module's fix to findings from the last scan: every finding, the keys given, or the
first `--limit`. The key is the first column of `scan`'s output — the file path for unregistered
files, the attachment ID for missing files. Asks for confirmation unless `--yes` is given.

```bash
# Try ten first
$ wp media-library-fixer fix unregistered-files --limit=10

# One file
$ wp media-library-fixer fix unregistered-files 2021/10/annual-report.pdf

# The rest
$ wp media-library-fixer fix unregistered-files --yes
```

Each item is logged as it is done; failures are listed at the end and in the PHP error log.
Interrupting the command (Ctrl+C) leaves the run in progress; running `fix` again, or the tool
page, offers to continue it.

## `wp media-library-fixer undo-import [<attachment-id>...] [--yes]`

Deletes attachments that Media Library Fixer imported — every one, or the IDs given — and **keeps
their files**. Generated previews and resized copies are deleted with the records. The files then
show up in the next scan again.

Anything done to those Media Library items since the import (new titles, descriptions, categories,
use in posts) is lost with them, so this is for backing out an import that went wrong, soon after
it ran. Attachments the plugin didn't create are refused.

```bash
$ wp media-library-fixer undo-import --yes
Success: 2364 removed, 0 failed. Their files were kept.
```
