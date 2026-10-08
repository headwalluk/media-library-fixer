=== Media Library Fixer ===
Contributors: headwalluk
Tags: media library, attachments, uploads, pdf, migration
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Resync the Media Library with the uploads folder: register files WordPress doesn't know about, and find attachments whose files have gone.

== Description ==

Adds Tools → Fix Media Library. Each task scans first and changes nothing until you choose what to fix.

* Files missing from the Media Library: registers files already in the uploads folder as attachments, in place. PDFs are dated from their own creation date and titled, described and made searchable from their document properties.
* Media Library items with missing files: lists attachments whose file has gone, and deletes the records you choose.

Large libraries are processed in short batches with a progress bar. WP-CLI commands cover the same tasks. Developers can add tasks as modules through the mlfixer_modules filter.

Documentation: https://github.com/headwalluk/media-library-fixer/tree/main/docs

== Installation ==

1. Copy the media-library-fixer folder into wp-content/plugins/
2. Activate the plugin
3. Review Settings → Media Library Fixer, then open Tools → Fix Media Library

Take a backup before importing or deleting in bulk.

== Frequently Asked Questions ==

= Are files moved or copied when they are imported? =

No. The attachment record points at the file where it already is, so its URL is unchanged.

= Can I undo an import? =

Yes, with WP-CLI: wp media-library-fixer undo-import deletes the imported records and keeps the files. Don't delete imported items from the Media Library instead: that deletes the file too, as it does for any attachment.

= Why weren't PDF previews made? =

They are off by default. If they are turned on and still not made, the server's ImageMagick can't read PDFs, often because its security policy blocks them. The settings page says whether previews can be made. WP-CLI sometimes can when the web server can't.

== Changelog ==

= 0.1.0 =
* First release.
