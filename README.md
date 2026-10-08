# Media Library Fixer

[![PHP](https://img.shields.io/badge/PHP-8.2+-purple.svg)](https://www.php.net/)
[![WordPress](https://img.shields.io/badge/WordPress-6.2+-21759B.svg)](https://wordpress.org/)
[![License](https://img.shields.io/badge/license-GPL--2.0+-green.svg)](LICENSE)

Resync the WordPress Media Library with the uploads folder.

After a migration or a rebuild, a site often has files in `wp-content/uploads` that the Media
Library doesn't know about, and Media Library items whose files never arrived. This plugin finds
both and fixes the ones you choose.

## What it does

- **Tools → Fix Media Library**: scan, review, then fix the items you tick. Scanning never changes
  anything
- **Registers files already in uploads** as attachments, in place. Nothing is moved or copied, so
  existing links keep working
- **Dates PDFs from their own creation date**, not the migration date, falling back to the upload
  folder. Titles, authors, subjects and keywords are read from the PDF too — no PHP extension or
  command-line tool needed
- **Finds attachments whose files are missing**, and deletes the records you choose
- Large libraries are handled in short batches with a progress bar; a run can be stopped and
  resumed
- WP-CLI commands for the same tasks
- Extensible: add your own hygiene task as a module with one filter

## Install

1. Copy the `media-library-fixer` folder into `wp-content/plugins/`
2. Activate it under Plugins
3. Review **Settings → Media Library Fixer**, then open **Tools → Fix Media Library**

Take a backup before importing or deleting in bulk.

### Requirements

- WordPress 6.2 or later
- PHP 8.2 or later
- For PDF preview images: ImageMagick with Ghostscript, allowed to read PDFs

## Documentation

- [How it works](docs/how-it-works.md)
- [Configuration](docs/configuration.md)
- [WP-CLI reference](docs/wp-cli.md)
- [Hooks and filters](docs/developers/hooks-and-filters.md) *(for developers)*
- [Writing a module](docs/developers/writing-a-module.md) *(for developers)*

## License

GPL v2 or later. See [LICENSE](LICENSE).
