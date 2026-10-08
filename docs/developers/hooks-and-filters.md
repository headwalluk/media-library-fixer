# Hooks and filters

The public extension surface of Media Library Fixer. Anything not listed here, or in
[writing a module](writing-a-module.md), is internal and may change between releases. Functions in
the `Media_Library_Fixer` namespace are private.

A filter's name and arguments don't change within a major version, and new arguments are only ever
added at the end. Every filter's result is checked: a value of the wrong type falls back to the
default.

## Modules

### `mlfixer_modules`

The modules shown on Tools → Fix Media Library and available to WP-CLI, in display order.

**Parameters:** `Module[] $modules` — the built-in modules.
**Returns:** `Module[]`. Entries that aren't `Media_Library_Fixer\Module` instances, have an invalid
ID or repeat an ID are skipped and logged.

```php
add_filter( 'mlfixer_modules', function ( $modules ) {
    $modules[] = new My_Plugin\Missing_Sizes_Module();
    return $modules;
} );

// Hide the delete-records module on this site.
add_filter( 'mlfixer_modules', function ( $modules ) {
    return array_filter( $modules, fn( $module ) => 'missing-files' !== $module->get_id() );
} );
```

Applied once per request, on first use. Add the filter before `admin_init`.

### `mlfixer_batch_time_limit`

Seconds of work in each batch request before the browser is handed control back. Default `8`.
Lower it behind a proxy with a short timeout; there is little gain in raising it.

```php
add_filter( 'mlfixer_batch_time_limit', fn() => 4 );
```

Each batch handles at least one unit or item, however long it takes.

## Unregistered files

### `mlfixer_excluded_directories`

Folder names skipped when **Folders to scan** is set to the whole uploads folder. Matched against
each folder's name at any depth.

**Parameters:** `string[] $names`.

```php
add_filter( 'mlfixer_excluded_directories', function ( $names ) {
    $names[] = 'private-exports';
    return $names;
} );
```

### `mlfixer_include_unregistered_file`

Whether the scan lists a file it has found.

**Parameters:** `bool $include` (default `true`), `string $relative_path` — relative to uploads.

```php
// Leave invoice PDFs out of the Media Library.
add_filter( 'mlfixer_include_unregistered_file', function ( $include, $relative_path ) {
    return str_contains( $relative_path, '/invoices/' ) ? false : $include;
}, 10, 2 );
```

### `mlfixer_pdf_max_bytes`

Largest PDF, in bytes, whose metadata is read. Larger files are imported without it. Default 64 MB.

**Parameters:** `int $max_bytes`, `string $path` — absolute.

### `mlfixer_document_metadata`

The document properties of a file being imported, before they set the date, title and description.
Use it to supply properties for types the plugin doesn't read, or to correct them.

**Parameters:**

- `array $metadata` — keys `title`, `author`, `subject`, `keywords`, `creator`, `producer` (strings)
  and `created`, `modified` (Unix timestamps or `null`). Empty for anything but PDF
- `string $path` — absolute
- `string $mime_type`

```php
// Date Word documents from their own properties, via a library you already have.
add_filter( 'mlfixer_document_metadata', function ( $metadata, $path, $mime_type ) {
    if ( 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' === $mime_type ) {
        $metadata['created'] = my_read_docx_created_date( $path );
    }
    return $metadata;
}, 10, 3 );
```

### `mlfixer_import_attachment_data`

The post data for `wp_insert_attachment()`, just before an attachment is created.

**Parameters:** `array $attachment_data`, `string $relative_path`, `array $metadata` — as above.

```php
// Attach imports to a "Documents archive" page.
add_filter( 'mlfixer_import_attachment_data', function ( $data ) {
    $data['post_parent'] = 1234;
    return $data;
} );
```

### `mlfixer_attachment_imported` (action)

Fires after a file has been registered and its metadata generated.

**Parameters:** `int $attachment_id`, `string $relative_path`, `array $metadata`.

```php
add_action( 'mlfixer_attachment_imported', function ( $attachment_id, $relative_path, $metadata ) {
    if ( '' !== $metadata['author'] ) {
        update_post_meta( $attachment_id, 'document_author', $metadata['author'] );
    }
}, 10, 3 );
```

## Stored data

Imported attachments carry the post meta key `_mlfixer_imported`, a Unix timestamp. It is safe to
read, for example to list everything an import created:

```php
$imported = get_posts( array(
    'post_type'   => 'attachment',
    'post_status' => 'inherit',
    'numberposts' => -1,
    'meta_key'    => '_mlfixer_imported',
) );
```

Everything else the plugin stores — its options and saved scan results — is internal.
