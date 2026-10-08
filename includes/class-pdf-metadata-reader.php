<?php
/**
 * PDF Metadata Reader
 *
 * @package Media_Library_Fixer
 * @since 0.1.0
 */

namespace Media_Library_Fixer;

// Exit if accessed directly.
defined( 'ABSPATH' ) || die();

/**
 * Reads the document information dictionary and XMP metadata from a PDF.
 *
 * Not a general PDF parser: it indexes "N G obj" markers, inflates object
 * streams, and reads only the handful of objects metadata lives in. See
 * docs/how-it-works.md, "Reading PDF metadata".
 *
 * @since 0.1.0
 */
class Pdf_Metadata_Reader {

	/**
	 * Info dictionary keys read, mapped to the result keys.
	 *
	 * @since 0.1.0
	 * @var array<string,string>
	 */
	private const INFO_KEYS = array(
		'Title'        => 'title',
		'Author'       => 'author',
		'Subject'      => 'subject',
		'Keywords'     => 'keywords',
		'Creator'      => 'creator',
		'Producer'     => 'producer',
		'CreationDate' => 'created',
		'ModDate'      => 'modified',
	);

	/**
	 * Raw file content.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private string $content = '';

	/**
	 * Byte offset of the last "N G obj" marker for each object number.
	 *
	 * @since 0.1.0
	 * @var array<int,int>
	 */
	private array $object_offsets = array();

	/**
	 * Objects unpacked from object streams, by object number. Null until first needed.
	 *
	 * @since 0.1.0
	 * @var array<int,string>|null
	 */
	private ?array $packed_objects = null;

	/**
	 * Read metadata from a PDF file.
	 *
	 * @since 0.1.0
	 * @param string $path Absolute path to the PDF.
	 * @return array{title:string,author:string,subject:string,keywords:string,creator:string,producer:string,created:?int,modified:?int} Empty strings and nulls where nothing was found.
	 */
	public function read( string $path ): array {
		$metadata = array(
			'title'    => '',
			'author'   => '',
			'subject'  => '',
			'keywords' => '',
			'creator'  => '',
			'producer' => '',
			'created'  => null,
			'modified' => null,
		);

		/**
		 * Filter the largest PDF, in bytes, that the metadata reader loads into memory.
		 *
		 * @since 0.1.0
		 *
		 * @param int    $max_bytes Default PDF_MAX_BYTES (64 MB).
		 * @param string $path      Absolute path to the PDF.
		 */
		$max_bytes = apply_filters( 'mlfixer_pdf_max_bytes', PDF_MAX_BYTES, $path );
		$max_bytes = is_numeric( $max_bytes ) ? (int) $max_bytes : PDF_MAX_BYTES;
		$file_size = is_readable( $path ) ? (int) filesize( $path ) : 0;

		if ( $file_size <= 0 ) {
			log( sprintf( 'PDF metadata: %s is empty or unreadable.', $path ) );
		} elseif ( $file_size > $max_bytes ) {
			log( sprintf( 'PDF metadata: %s is %d bytes, over the %d-byte limit; not read.', $path, $file_size, $max_bytes ) );
		} else {
			$this->content        = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
			$this->object_offsets = array();
			$this->packed_objects = null;

			if ( false === strpos( substr( $this->content, 0, 1024 ), '%PDF-' ) ) {
				log( sprintf( 'PDF metadata: %s has no PDF header.', $path ) );
			} else {
				$this->index_objects();

				// Strings in an encrypted file's Info dictionary are ciphertext.
				if ( 1 !== preg_match( '/\/Encrypt\s*(?:\d+\s+\d+\s+R|<<)/', $this->content ) ) {
					$metadata = $this->merge_missing( $metadata, $this->read_info_dictionary() );
				}

				$metadata = $this->merge_missing( $metadata, $this->read_xmp() );
			}

			$this->content        = '';
			$this->object_offsets = array();
			$this->packed_objects = null;
		}

		return $metadata;
	}

	/**
	 * Fill empty values in $metadata from $extra.
	 *
	 * @since 0.1.0
	 * @param array $metadata Values found so far.
	 * @param array $extra    Values from a lower-priority source.
	 * @return array
	 */
	private function merge_missing( array $metadata, array $extra ): array {
		foreach ( $extra as $key => $value ) {
			if ( array_key_exists( $key, $metadata ) && ( null === $metadata[ $key ] || '' === $metadata[ $key ] ) ) {
				$metadata[ $key ] = $value;
			}
		}

		return $metadata;
	}

	/**
	 * Record the offset of every "N G obj" marker; a later definition replaces an earlier one.
	 *
	 * @since 0.1.0
	 */
	private function index_objects(): void {
		$matches = array();

		if ( false === preg_match_all( '/(?<![0-9])(\d+)\s+\d+\s+obj\b/', $this->content, $matches, PREG_OFFSET_CAPTURE ) ) {
			log( 'PDF metadata: object index failed, PCRE error ' . preg_last_error() );
		} else {
			foreach ( $matches[1] as $index => $number_match ) {
				$this->object_offsets[ (int) $number_match[0] ] = (int) $matches[0][ $index ][1];
			}
		}
	}

	/**
	 * Get an object's body, from the file itself or from an object stream.
	 *
	 * @since 0.1.0
	 * @param int $object_number Object number.
	 * @return string|null Text between "obj" and "endobj", or null when not found.
	 */
	private function get_object( int $object_number ): ?string {
		$body = null;

		if ( isset( $this->object_offsets[ $object_number ] ) ) {
			$start = strpos( $this->content, 'obj', $this->object_offsets[ $object_number ] ) + 3;
			$end   = strpos( $this->content, 'endobj', $start );

			if ( false !== $end ) {
				$body = substr( $this->content, $start, $end - $start );
			}
		}

		if ( null === $body ) {
			$body = $this->get_packed_objects()[ $object_number ] ?? null;
		}

		return $body;
	}

	/**
	 * Unpack every object stream in the file, once.
	 *
	 * @since 0.1.0
	 * @return array<int,string> Object bodies by object number.
	 */
	private function get_packed_objects(): array {
		if ( null === $this->packed_objects ) {
			$this->packed_objects = array();

			foreach ( $this->object_offsets as $object_number => $offset ) {
				$end = strpos( $this->content, 'stream', $offset );

				// Only the dictionary is checked, so a large stream body is never copied unless it is an object stream.
				if ( false !== $end && 1 === preg_match( '/\/Type\s*\/ObjStm\b/', substr( $this->content, $offset, min( $end - $offset, 2048 ) ) ) ) {
					$this->unpack_object_stream( (string) $this->get_object( $object_number ) );
				}
			}
		}

		return $this->packed_objects;
	}

	/**
	 * Add the objects held in one object stream to $packed_objects.
	 *
	 * @since 0.1.0
	 * @param string $body Body of the object stream object.
	 */
	private function unpack_object_stream( string $body ): void {
		$decoded     = $this->get_stream_data( $body );
		$count_match = array();
		$first_match = array();

		if ( null !== $decoded && 1 === preg_match( '/\/N\s+(\d+)/', $body, $count_match ) && 1 === preg_match( '/\/First\s+(\d+)/', $body, $first_match ) ) {
			$first_offset = (int) $first_match[1];
			$header       = preg_split( '/\s+/', trim( substr( $decoded, 0, $first_offset ) ) );
			$entry_count  = min( (int) $count_match[1], intdiv( count( $header ), 2 ) );

			for ( $entry_index = 0; $entry_index < $entry_count; ++$entry_index ) {
				$object_number = (int) $header[ $entry_index * 2 ];
				$start         = $first_offset + (int) $header[ $entry_index * 2 + 1 ];
				$next_start    = ( $entry_index + 1 < $entry_count ) ? $first_offset + (int) $header[ ( $entry_index + 1 ) * 2 + 1 ] : strlen( $decoded );

				$this->packed_objects[ $object_number ] = substr( $decoded, $start, $next_start - $start );
			}
		}
	}

	/**
	 * Extract and decode the stream in an object body.
	 *
	 * @since 0.1.0
	 * @param string $body Object body.
	 * @return string|null Decoded data, or null when absent or in an unsupported encoding.
	 */
	private function get_stream_data( string $body ): ?string {
		$data         = null;
		$stream_start = strpos( $body, 'stream' );
		$stream_end   = strrpos( $body, 'endstream' );

		if ( false !== $stream_start && false !== $stream_end && $stream_end > $stream_start ) {
			$dictionary = substr( $body, 0, $stream_start );
			$raw        = substr( $body, $stream_start + 6, $stream_end - $stream_start - 6 );

			// The keyword is followed by CRLF or LF; the data may end with an EOL before "endstream".
			$raw = preg_replace( '/^\r?\n/', '', $raw );
			$raw = preg_replace( '/\r?\n$/', '', $raw );

			$filters = $this->get_stream_filters( $dictionary );

			if ( array() === $filters ) {
				$data = $raw;
			} elseif ( array( 'FlateDecode' ) === $filters && 1 !== preg_match( '/\/Predictor\s+(?:[2-9]|1\d)/', $dictionary ) ) {
				$data = $this->inflate( $raw );
			} else {
				// Other filters and predictors aren't used for metadata or object streams in practice.
				log( 'PDF metadata: skipped a stream with an unsupported filter.' );
			}
		}

		return $data;
	}

	/**
	 * List a stream dictionary's filters, leaving out /Crypt.
	 *
	 * A /Crypt filter in a stream's own dictionary names the identity crypt filter in
	 * practice, used to keep XMP readable in an encrypted file.
	 *
	 * @since 0.1.0
	 * @param string $dictionary Stream dictionary text.
	 * @return string[] Filter names without slashes, in order.
	 */
	private function get_stream_filters( string $dictionary ): array {
		$filters      = array();
		$filter_match = array();
		$name_matches = array();

		if ( 1 === preg_match( '/\/Filter\s*(\[[^\]]*\]|\/\w+)/', $dictionary, $filter_match ) ) {
			preg_match_all( '/\/(\w+)/', $filter_match[1], $name_matches );
			$filters = array_values( array_diff( $name_matches[1], array( 'Crypt' ) ) );
		}

		return $filters;
	}

	/**
	 * Inflate FlateDecode data, tolerating a missing or damaged checksum.
	 *
	 * @since 0.1.0
	 * @param string $raw Compressed data.
	 * @return string|null Decompressed data, or null when it can't be decoded.
	 */
	private function inflate( string $raw ): ?string {
		$warning = '';

		// zlib reports corrupt input as a PHP warning; capture it instead of printing it.
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Captures zlib warnings for one call; restored below.
		set_error_handler(
			static function ( int $error_number, string $error_message ) use ( &$warning ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- Signature required by set_error_handler().
				$warning = $error_message;
				return true;
			}
		);

		$inflated = gzuncompress( $raw );

		if ( false === $inflated ) {
			// Some producers write truncated streams; incremental inflation returns what decodes.
			$context  = inflate_init( ZLIB_ENCODING_DEFLATE );
			$inflated = false === $context ? false : inflate_add( $context, $raw, ZLIB_SYNC_FLUSH );
		}

		restore_error_handler();

		if ( false === $inflated || '' === $inflated ) {
			log( 'PDF metadata: could not inflate a stream: ' . $warning );
			$inflated = null;
		}

		return $inflated;
	}

	/**
	 * Find the reference to a trailer entry such as /Info or /Root.
	 *
	 * @since 0.1.0
	 * @param string $key Entry name without the slash.
	 * @return int|null Object number from the last occurrence, which is the newest revision.
	 */
	private function get_trailer_reference( string $key ): ?int {
		$matches          = array();
		$reference_number = null;

		if ( preg_match_all( '/\/' . $key . '\s+(\d+)\s+\d+\s+R/', $this->content, $matches ) > 0 ) {
			$reference_number = (int) end( $matches[1] );
		}

		return $reference_number;
	}

	/**
	 * Read the document information dictionary.
	 *
	 * @since 0.1.0
	 * @return array Result keys found, with string or timestamp values.
	 */
	private function read_info_dictionary(): array {
		$values      = array();
		$info_number = $this->get_trailer_reference( 'Info' );
		$dictionary  = null === $info_number ? $this->get_inline_info_dictionary() : $this->get_object( $info_number );

		if ( null !== $dictionary ) {
			foreach ( self::INFO_KEYS as $pdf_key => $result_key ) {
				$raw_value = $this->get_dictionary_string( $dictionary, $pdf_key );

				if ( null === $raw_value ) {
					// Key absent.
				} elseif ( 'created' === $result_key || 'modified' === $result_key ) {
					$values[ $result_key ] = $this->parse_pdf_date( $this->decode_text( $raw_value ) );
				} else {
					$values[ $result_key ] = $this->decode_text( $raw_value );
				}
			}
		}

		return $values;
	}

	/**
	 * Get an Info dictionary written directly in the trailer instead of referenced.
	 *
	 * Not allowed by the specification, but some producers write it and readers accept it.
	 *
	 * @since 0.1.0
	 * @return string|null Dictionary text from the last occurrence, or null when there is none.
	 */
	private function get_inline_info_dictionary(): ?string {
		$dictionary = null;
		$matches    = array();

		if ( preg_match_all( '/\/Info\s*<</', $this->content, $matches, PREG_OFFSET_CAPTURE ) > 0 ) {
			$last_match = end( $matches[0] );
			$start      = $last_match[1] + strlen( $last_match[0] ) - 2;
			$end        = $this->find_dictionary_end( $start );
			$dictionary = null === $end ? null : substr( $this->content, $start, $end - $start );
		}

		return $dictionary;
	}

	/**
	 * Find the end of the dictionary opening at $start, skipping nested dictionaries and strings.
	 *
	 * @since 0.1.0
	 * @param int $start Offset of the opening "<<".
	 * @return int|null Offset just past the matching ">>", or null when unbalanced.
	 */
	private function find_dictionary_end( int $start ): ?int {
		$end      = null;
		$depth    = 0;
		$length   = strlen( $this->content );
		$position = $start;

		while ( null === $end && $position < $length ) {
			$pair = substr( $this->content, $position, 2 );

			if ( '<<' === $pair ) {
				++$depth;
				$position += 2;
			} elseif ( '>>' === $pair ) {
				--$depth;
				$position += 2;
				$end       = 0 === $depth ? $position : null;
			} elseif ( '(' === $pair[0] ) {
				$position += 1 + strlen( $this->get_raw_literal_string( $position + 1 ) );
			} elseif ( '<' === $pair[0] ) {
				// Hex string.
				$close    = strpos( $this->content, '>', $position );
				$position = false === $close ? $length : $close + 1;
			} else {
				++$position;
			}
		}

		return $end;
	}

	/**
	 * Get a literal string's source text, escapes intact, through its closing parenthesis.
	 *
	 * @since 0.1.0
	 * @param int $start Offset just after the opening parenthesis.
	 * @return string
	 */
	private function get_raw_literal_string( int $start ): string {
		$depth    = 1;
		$length   = strlen( $this->content );
		$position = $start;

		while ( $position < $length && $depth > 0 ) {
			$character = $this->content[ $position ];

			if ( '\\' === $character ) {
				++$position;
			} elseif ( '(' === $character ) {
				++$depth;
			} elseif ( ')' === $character ) {
				--$depth;
			} else {
				// Ordinary character.
			}

			++$position;
		}

		return substr( $this->content, $start, $position - $start );
	}

	/**
	 * Get a string value from a dictionary, following one indirect reference.
	 *
	 * @since 0.1.0
	 * @param string $dictionary Dictionary text.
	 * @param string $key        Key without the slash.
	 * @return string|null Raw string bytes after escape processing, or null when absent.
	 */
	private function get_dictionary_string( string $dictionary, string $key ): ?string {
		$value       = null;
		$key_match   = array();
		$ref_matches = array();

		if ( 1 === preg_match( '/\/' . $key . '(?=[\s(<\/\d])\s*/', $dictionary, $key_match, PREG_OFFSET_CAPTURE ) ) {
			$position = $key_match[0][1] + strlen( $key_match[0][0] );

			if ( 1 === preg_match( '/\G(\d+)\s+\d+\s+R/', $dictionary, $ref_matches, 0, $position ) ) {
				$referenced = $this->get_object( (int) $ref_matches[1] );
				$value      = null === $referenced ? null : $this->parse_string_at( ltrim( $referenced ), 0 );
			} else {
				$value = $this->parse_string_at( $dictionary, $position );
			}
		}

		return $value;
	}

	/**
	 * Parse a literal "(...)" or hex "<...>" string starting at $position.
	 *
	 * @since 0.1.0
	 * @param string $text     Source text.
	 * @param int    $position Offset of the opening delimiter.
	 * @return string|null Unescaped bytes, or null when no string starts there.
	 */
	private function parse_string_at( string $text, int $position ): ?string {
		$value     = null;
		$delimiter = $text[ $position ] ?? '';

		if ( '(' === $delimiter ) {
			$value = $this->parse_literal_string( $text, $position + 1 );
		} elseif ( '<' === $delimiter && '<' !== ( $text[ $position + 1 ] ?? '' ) ) {
			$end = strpos( $text, '>', $position );

			if ( false !== $end ) {
				$hex = preg_replace( '/[^0-9a-fA-F]/', '', substr( $text, $position + 1, $end - $position - 1 ) );

				// An odd final digit is padded with zero, per the PDF specification.
				$value = (string) hex2bin( strlen( $hex ) % 2 ? $hex . '0' : $hex );
			}
		} else {
			// Not a string: a name, number or nested dictionary.
		}

		return $value;
	}

	/**
	 * Unescape a literal string body, honouring nested parentheses.
	 *
	 * @since 0.1.0
	 * @param string $text  Source text.
	 * @param int    $start Offset just after the opening parenthesis.
	 * @return string Unescaped bytes.
	 */
	private function parse_literal_string( string $text, int $start ): string {
		$escapes  = array(
			'n' => "\n",
			'r' => "\r",
			't' => "\t",
			'b' => "\x08",
			'f' => "\f",
		);
		$output   = '';
		$depth    = 1;
		$length   = strlen( $text );
		$position = $start;

		while ( $position < $length && $depth > 0 ) {
			$character = $text[ $position ];

			if ( '\\' === $character && $position + 1 < $length ) {
				$next = $text[ $position + 1 ];

				if ( isset( $escapes[ $next ] ) ) {
					$output   .= $escapes[ $next ];
					$position += 2;
				} elseif ( ctype_digit( $next ) && $next < '8' ) {
					$octal_match = array();
					preg_match( '/\G[0-7]{1,3}/', $text, $octal_match, 0, $position + 1 );
					$output   .= chr( octdec( $octal_match[0] ) & 0xFF );
					$position += 1 + strlen( $octal_match[0] );
				} elseif ( "\r" === $next || "\n" === $next ) {
					// Backslash before a line break continues the string on the next line.
					$position += ( "\r" === $next && "\n" === ( $text[ $position + 2 ] ?? '' ) ) ? 3 : 2;
				} else {
					$output   .= $next;
					$position += 2;
				}
			} else {
				if ( '(' === $character ) {
					++$depth;
				} elseif ( ')' === $character ) {
					--$depth;
				} else {
					// Ordinary character.
				}

				if ( $depth > 0 ) {
					$output .= $character;
				}

				++$position;
			}
		}

		return $output;
	}

	/**
	 * Convert PDF text-string bytes to clean single-line UTF-8.
	 *
	 * @since 0.1.0
	 * @param string $bytes Raw bytes.
	 * @return string
	 */
	private function decode_text( string $bytes ): string {
		if ( str_starts_with( $bytes, "\xFE\xFF" ) ) {
			$text = mb_convert_encoding( substr( $bytes, 2 ), 'UTF-8', 'UTF-16BE' );
		} elseif ( str_starts_with( $bytes, "\xFF\xFE" ) ) {
			$text = mb_convert_encoding( substr( $bytes, 2 ), 'UTF-8', 'UTF-16LE' );
		} elseif ( str_starts_with( $bytes, "\xEF\xBB\xBF" ) ) {
			$text = substr( $bytes, 3 );
		} elseif ( mb_check_encoding( $bytes, 'UTF-8' ) ) {
			// Not valid PDFDocEncoding, but many producers write UTF-8 anyway.
			$text = $bytes;
		} else {
			$text = $this->decode_pdf_doc_encoding( $bytes );
		}

		$text = preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', (string) $text );

		return trim( (string) preg_replace( '/\s+/u', ' ', (string) $text ) );
	}

	/**
	 * Convert PDFDocEncoding bytes to UTF-8.
	 *
	 * Latin-1 apart from 0x80–0xA0, which hold typographic characters in an order
	 * of their own (not Windows-1252's).
	 *
	 * @since 0.1.0
	 * @param string $bytes Raw bytes.
	 * @return string
	 */
	private function decode_pdf_doc_encoding( string $bytes ): string {
		$high_characters = array( '•', '†', '‡', '…', '—', '–', 'ƒ', '⁄', '‹', '›', '−', '‰', '„', '“', '”', '‘', '’', '‚', '™', 'ﬁ', 'ﬂ', 'Ł', 'Œ', 'Š', 'Ÿ', 'Ž', 'ı', 'ł', 'œ', 'š', 'ž', '', '€' );
		$text            = '';
		$length          = strlen( $bytes );

		for ( $position = 0; $position < $length; ++$position ) {
			$code = ord( $bytes[ $position ] );

			if ( $code < 0x80 ) {
				$text .= $bytes[ $position ];
			} elseif ( $code <= 0xA0 ) {
				$text .= $high_characters[ $code - 0x80 ];
			} else {
				$text .= mb_chr( $code, 'UTF-8' );
			}
		}

		return $text;
	}

	/**
	 * Parse a PDF date string ("D:YYYYMMDDHHmmSSOHH'mm'") into a Unix timestamp.
	 *
	 * @since 0.1.0
	 * @param string $value Date string.
	 * @return int|null Null when malformed or outside the plausible range.
	 */
	public function parse_pdf_date( string $value ): ?int {
		$timestamp = null;
		$parts     = array();

		if ( 1 === preg_match( '/^(?:D:)?(\d{4})(\d{2})?(\d{2})?(\d{2})?(\d{2})?(\d{2})?\s*(?:([Zz+\-])\s*(\d{2})?\'?(\d{2})?\'?)?/', trim( $value ), $parts ) ) {
			$year   = (int) $parts[1];
			$month  = (int) ( '' !== ( $parts[2] ?? '' ) ? $parts[2] : 1 );
			$day    = (int) ( '' !== ( $parts[3] ?? '' ) ? $parts[3] : 1 );
			$hour   = (int) ( $parts[4] ?? 0 );
			$minute = (int) ( $parts[5] ?? 0 );
			$second = (int) ( $parts[6] ?? 0 );
			$sign   = $parts[7] ?? '';

			if ( '' === $sign ) {
				// No offset given: read it as the site's local time.
				$timezone = wp_timezone();
			} elseif ( 'Z' === strtoupper( $sign ) ) {
				$timezone = new \DateTimeZone( 'UTC' );
			} else {
				$timezone = new \DateTimeZone( sprintf( '%s%02d:%02d', $sign, (int) ( $parts[8] ?? 0 ), (int) ( $parts[9] ?? 0 ) ) );
			}

			if ( checkdate( $month, $day, $year ) && $hour < 24 && $minute < 60 && $second < 60 ) {
				$date      = date_create_immutable( sprintf( '%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second ), $timezone );
				$timestamp = false === $date ? null : $this->plausible_timestamp( $date->getTimestamp() );
			}
		}

		return $timestamp;
	}

	/**
	 * Reject dates before PDF existed or in the future.
	 *
	 * @since 0.1.0
	 * @param int $timestamp Candidate.
	 * @return int|null
	 */
	private function plausible_timestamp( int $timestamp ): ?int {
		$earliest = (int) strtotime( PDF_EARLIEST_DATE . ' 00:00:00 UTC' );

		return ( $timestamp >= $earliest && $timestamp <= time() + DAY_IN_SECONDS ) ? $timestamp : null;
	}

	/**
	 * Read the catalog's XMP metadata stream.
	 *
	 * @since 0.1.0
	 * @return array Result keys found.
	 */
	private function read_xmp(): array {
		$values      = array();
		$xmp         = null;
		$root_number = $this->get_trailer_reference( 'Root' );
		$catalog     = null === $root_number ? null : $this->get_object( $root_number );
		$ref_match   = array();

		// Only the catalog's stream describes the document; images can carry XMP of their own.
		if ( null !== $catalog && 1 === preg_match( '/\/Metadata\s+(\d+)\s+\d+\s+R/', $catalog, $ref_match ) ) {
			$stream_object = $this->get_object( (int) $ref_match[1] );
			$xmp           = null === $stream_object ? null : $this->get_stream_data( $stream_object );
		}

		if ( null !== $xmp ) {
			$values = array(
				'title'    => $this->get_xmp_list_item( $xmp, 'dc:title' ),
				'author'   => $this->get_xmp_list_item( $xmp, 'dc:creator' ),
				'subject'  => $this->get_xmp_list_item( $xmp, 'dc:description' ),
				'keywords' => $this->get_xmp_property( $xmp, 'pdf:Keywords' ),
				'creator'  => $this->get_xmp_property( $xmp, 'xmp:CreatorTool' ),
				'producer' => $this->get_xmp_property( $xmp, 'pdf:Producer' ),
				'created'  => $this->parse_xmp_date( $this->get_xmp_property( $xmp, 'xmp:CreateDate' ) ),
				'modified' => $this->parse_xmp_date( $this->get_xmp_property( $xmp, 'xmp:ModifyDate' ) ),
			);
		}

		return $values;
	}

	/**
	 * Get a simple XMP property, written either as an element or as an attribute.
	 *
	 * @since 0.1.0
	 * @param string $xmp      XMP packet.
	 * @param string $property Qualified property name.
	 * @return string
	 */
	private function get_xmp_property( string $xmp, string $property ): string {
		$value   = '';
		$matches = array();

		if ( 1 === preg_match( '/<' . preg_quote( $property, '/' ) . '>([^<]*)<\//', $xmp, $matches ) || 1 === preg_match( '/\s' . preg_quote( $property, '/' ) . '="([^"]*)"/', $xmp, $matches ) ) {
			$value = $this->decode_text( html_entity_decode( $matches[1], ENT_QUOTES | ENT_XML1, 'UTF-8' ) );
		}

		return $value;
	}

	/**
	 * Get the first item of an XMP rdf:Alt, rdf:Seq or rdf:Bag property.
	 *
	 * @since 0.1.0
	 * @param string $xmp      XMP packet.
	 * @param string $property Qualified property name.
	 * @return string
	 */
	private function get_xmp_list_item( string $xmp, string $property ): string {
		$value   = '';
		$matches = array();

		$item_match = array();
		$quoted     = preg_quote( $property, '/' );

		// The item is looked for inside the property's own element, so an empty list can't pick up the next property's item.
		if ( 1 === preg_match( '/<' . $quoted . '>(.*?)<\/' . $quoted . '>/s', $xmp, $matches ) && 1 === preg_match( '/<rdf:li[^>]*>([^<]*)<\/rdf:li>/', $matches[1], $item_match ) ) {
			$value = $this->decode_text( html_entity_decode( $item_match[1], ENT_QUOTES | ENT_XML1, 'UTF-8' ) );
		}

		return $value;
	}

	/**
	 * Parse an XMP (ISO 8601) date into a Unix timestamp.
	 *
	 * @since 0.1.0
	 * @param string $value Date string.
	 * @return int|null
	 */
	private function parse_xmp_date( string $value ): ?int {
		$timestamp = null;

		if ( '' !== $value ) {
			$date      = date_create_immutable( $value, wp_timezone() );
			$timestamp = false === $date ? null : $this->plausible_timestamp( $date->getTimestamp() );
		}

		return $timestamp;
	}
}
