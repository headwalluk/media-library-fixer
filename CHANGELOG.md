# Changelog

All notable changes to Media Library Fixer will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] — 2026-10-08

### Added

- **Tools → Fix Media Library**, with a scan-then-fix panel per module, batched progress, stop and resume, and saved results.
- **Files missing from the Media Library** module: finds files in uploads with no attachment record, skipping images WordPress generated from another file, and registers selected files in place.
- **PDF metadata** read without dependencies, including from compressed object streams, inline trailer dictionaries and XMP: creation date, title, author, subject and keywords set the attachment's date, title and description.
- **Media Library items with missing files** module: finds attachments whose file has gone and deletes selected records after confirmation, re-checking each first.
- **Settings → Media Library Fixer**: file types, folders to scan, date and title sources, description, PDF previews, delete data on uninstall. PDF previews are off by default, and the setting tests whether the server can make them.
- **WP-CLI**: `wp media-library-fixer modules`, `scan` and `fix`, sharing results with the tool page and resuming an interrupted run, and `undo-import`, which deletes imported records while keeping their files.
- **Extension points**: the `Module` base class and the `mlfixer_modules`, `mlfixer_batch_time_limit`, `mlfixer_excluded_directories`, `mlfixer_include_unregistered_file`, `mlfixer_pdf_max_bytes`, `mlfixer_document_metadata` and `mlfixer_import_attachment_data` filters, and the `mlfixer_attachment_imported` action.
