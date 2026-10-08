# Writing a module

A module is one Media Library hygiene task: a read-only scan that produces a list of *findings*,
and a fix applied to the findings the user selects. Media Library Fixer supplies the page, the
progress bar, batching, stop and resume, saved results, the WP-CLI commands and error handling.
A module supplies the scan and the fix.

## The contract

Extend `Media_Library_Fixer\Module` and register an instance through
[`mlfixer_modules`](hooks-and-filters.md#mlfixer_modules).

| Method | Returns |
|---|---|
| `get_id()` | Unique ID: lower-case letters, digits and hyphens. Used in option names and WP-CLI, so never change it |
| `get_title()` | Panel heading |
| `get_description()` | One or two sentences: what the scan looks for, what the fix does |
| `get_fix_label()` | Fix button label, e.g. "Regenerate selected" |
| `get_fix_confirmation()` | *Optional.* Question for a confirmation dialog; `''` (default) for none. Use one for anything destructive |
| `get_columns()` | *Optional.* `[ 'label' => …, 'detail' => … ]` column headings |
| `get_scan_units()` | Every unit of work, e.g. folder names or ID ranges. Must survive a JSON round trip |
| `scan_unit( $unit )` | The findings for one unit |
| `fix_finding( $finding )` | A one-line summary of what was done, or a `WP_Error` saying why not |

### Units

The scan is spread across requests by unit. Make a unit small enough to finish in a second or two
on a big site — a folder, a few hundred posts — and cheap to list: `get_scan_units()` runs once at
the start of every scan.

### Findings

```php
array(
    'key'    => '123',               // Unique within one scan; what the user selects
    'label'  => 'Annual report (#123)', // Plain text, first column
    'detail' => '2 of 4 sizes missing', // Plain text, second column
    'url'    => admin_url( 'post.php?post=123&action=edit' ), // Optional link for the label
    'data'   => array( 'attachment_id' => 123 ), // Optional; handed back to fix_finding()
)
```

Findings are stored between requests, so keep them small. `data` never reaches the browser.

### Fixing

Time passes between scan and fix. **Re-check the problem** in `fix_finding()` before acting, and
return a `WP_Error` if it has gone. Return a short sentence on success; it goes in the page's log
and the WP-CLI output.

Exceptions from `scan_unit()` and `fix_finding()` are caught, logged to the PHP error log and shown
to the user, and the run carries on with the next unit or item. Don't rely on that for expected
failures: return a `WP_Error`.

## Example: attachments missing image sizes

Lists images whose registered sizes are missing from disk, and regenerates them.

```php
<?php
namespace My_Plugin;

use Media_Library_Fixer\Module;

class Missing_Sizes_Module extends Module {

    public function get_id(): string {
        return 'missing-sizes';
    }

    public function get_title(): string {
        return __( 'Images with missing sizes', 'my-plugin' );
    }

    public function get_description(): string {
        return __( 'Finds images whose resized copies are missing from disk, and regenerates them.', 'my-plugin' );
    }

    public function get_fix_label(): string {
        return __( 'Regenerate selected', 'my-plugin' );
    }

    public function get_scan_units(): array {
        global $wpdb;

        $last_id = (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts} WHERE post_type = 'attachment'" );

        return array_map( fn( $first ) => array( $first, $first + 499 ), range( 1, max( 1, $last_id ), 500 ) );
    }

    public function scan_unit( mixed $unit ): array {
        $findings = array();
        $ids      = get_posts( array(
            'post_type'      => 'attachment',
            'post_mime_type' => 'image',
            'post_status'    => 'inherit',
            'numberposts'    => -1,
            'fields'         => 'ids',
            'post__in'       => range( $unit[0], $unit[1] ),
        ) );

        foreach ( $ids as $attachment_id ) {
            $missing = $this->count_missing_sizes( $attachment_id );

            if ( $missing > 0 ) {
                $findings[] = array(
                    'key'    => (string) $attachment_id,
                    'label'  => get_the_title( $attachment_id ) . " (#{$attachment_id})",
                    'detail' => sprintf( '%d size(s) missing', $missing ),
                    'url'    => admin_url( "post.php?post={$attachment_id}&action=edit" ),
                    'data'   => array( 'attachment_id' => $attachment_id ),
                );
            }
        }

        return $findings;
    }

    public function fix_finding( array $finding ): string|\WP_Error {
        $attachment_id = (int) $finding['data']['attachment_id'];
        $file          = get_attached_file( $attachment_id );

        if ( 0 === $this->count_missing_sizes( $attachment_id ) ) {
            $result = new \WP_Error( 'nothing_missing', 'All sizes are present now.' );
        } elseif ( ! $file || ! file_exists( $file ) ) {
            $result = new \WP_Error( 'no_original', 'The original image is missing.' );
        } else {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $file ) );
            $result = 'Sizes regenerated.';
        }

        return $result;
    }

    private function count_missing_sizes( int $attachment_id ): int {
        $metadata  = wp_get_attachment_metadata( $attachment_id );
        $directory = dirname( (string) get_attached_file( $attachment_id ) );
        $missing   = 0;

        foreach ( $metadata['sizes'] ?? array() as $size ) {
            if ( ! file_exists( $directory . '/' . $size['file'] ) ) {
                ++$missing;
            }
        }

        return $missing;
    }
}
```

```php
add_filter( 'mlfixer_modules', function ( $modules ) {
    $modules[] = new My_Plugin\Missing_Sizes_Module();
    return $modules;
} );
```

The new panel appears on Tools → Fix Media Library, and the module works from the command line at
once:

```bash
wp media-library-fixer scan missing-sizes
wp media-library-fixer fix missing-sizes --limit=20
```
