#!/usr/bin/env php
<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- Utility script, not a class file.
/**
 * PHP script to fix inline comment formatting
 * Ensures all inline comments end with proper punctuation (., !, or ?)
 *
 * Usage: php fix-inline-comments.php [directory_or_file]
 * If no argument provided, processes current directory recursively
 *
 * @package CommerceBird
 */

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI script, not WordPress context.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- CLI script.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- CLI script.

/**
 * Class to fix inline comment formatting.
 */
class InlineCommentFixer {

	/**
	 * Number of comments fixed.
	 *
	 * @var int
	 */
	private $fixed_count = 0;

	/**
	 * Number of files processed.
	 *
	 * @var int
	 */
	private $processed_files = 0;

	/**
	 * Main execution method
	 *
	 * @param string|null $path Directory or file to process. If null, uses current directory.
	 * @return int Exit code (0 = success, 1 = error)
	 */
	public function run( $path = null ) {
		$path = $path ?? getcwd();

		if ( is_file( $path ) ) {
			$this->process_file( $path );
		} elseif ( is_dir( $path ) ) {
			$this->process_directory( $path );
		} else {
			echo "Error: Path '$path' does not exist.\n";
			return 1;
		}

		echo "\nSummary:\n";
		echo "- Files processed: {$this->processed_files}\n";
		echo "- Comments fixed: {$this->fixed_count}\n";

		return 0;
	}

	/**
	 * Process all PHP files in directory recursively
	 *
	 * @param string $dir Directory path.
	 */
	private function process_directory( $dir ) {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir )
		);

		foreach ( $iterator as $file ) {
			if ( $file->isFile() && $file->getExtension() === 'php' ) {
				// Skip vendor, build, stubs, and node_modules directories.
				$path = $file->getPathname();
				if ( strpos( $path, '/vendor/' ) !== false ||
					strpos( $path, '\\vendor\\' ) !== false ||
					strpos( $path, '/build/' ) !== false ||
					strpos( $path, '\\build\\' ) !== false ||
					strpos( $path, '/stubs/' ) !== false ||
					strpos( $path, '\\stubs\\' ) !== false ||
					strpos( $path, '/node_modules/' ) !== false ||
					strpos( $path, '/.git/' ) !== false ) {
					continue;
				}
				// don't process this fixer script itself or the companion fixer that operates on yoda directories.
				$basename = basename( $path );
				if ( basename( __FILE__ ) === $basename || 'fix-yoda-directories.php' === $basename ) {
					continue;
				}

				$this->process_file( $path );
			}
		}
	}

	/**
	 * Process a single PHP file
	 *
	 * @param string $file_path File path to process.
	 */
	private function process_file( $file_path ) {
		// always show which file is being examined so callers know includes/ is covered.
		echo "Processing file: $file_path\n";
		if ( ! is_readable( $file_path ) ) {
			echo "Warning: Cannot read file '$file_path'\n";
			return;
		}

		$content          = file_get_contents( $file_path );
		$original_content = $content;

		// Fix inline comments.
		$content = $this->fix_inline_comments( $content );

		if ( $content !== $original_content ) {
			if ( file_put_contents( $file_path, $content ) ) {
				echo "Fixed inline comments in: $file_path\n";
			} else {
				echo "Error: Could not write to file '$file_path'\n";
			}
		}

		++$this->processed_files;
	}

	/**
	 * Fix inline comment formatting in content
	 *
	 * @param string $content File content to process.
	 * @return string Modified content.
	 */
	private function fix_inline_comments( $content ) {
		// Split content into lines for processing.
		$lines    = explode( "\n", $content );
		$modified = false;

		foreach ( $lines as $index => &$line ) {
			$original_line = $line;

			// Skip lines that are inside strings or have comment-like characters in strings.
			if ( $this->is_comment_in_string( $line ) ) {
				continue;
			}

			// Match different types of inline comments.
			$patterns = array(
				// Single line comments starting with //.
				'/^(\s*)\/\/\s*(.+?)(\s*)$/',
				// Hash comments starting with #.
				'/^(\s*)#\s*(.+?)(\s*)$/',
				// Inline comments after code // comment.
				'/^(.+?)(\s+)\/\/\s*(.+?)(\s*)$/',
				// Inline comments after code # comment.
				'/^(.+?)(\s+)#\s*(.+?)(\s*)$/',
			);

			foreach ( $patterns as $pattern ) {
				if ( preg_match( $pattern, $line, $matches ) ) {
					$line = $this->fix_comment_line( $line, $pattern, $matches );
					if ( $line !== $original_line ) {
						$modified = true;
						++$this->fixed_count;
					}
					break; // Only process first matching pattern.
				}
			}
		}

		return $modified ? implode( "\n", $lines ) : $content;
	}

	/**
	 * Check if the line contains comment-like characters inside strings
	 *
	 * @param string $line Line to check.
	 * @return bool True if comment characters are inside strings.
	 */
	private function is_comment_in_string( $line ) {
		// Simple check: if the line contains both quotes and comment chars,.
		// and the comment chars appear inside quotes, skip it.

		// Look for patterns like 'text with # or //' or "text with # or //".
		if ( preg_match( "/['\"][^'\"]*[#\/]+[^'\"]*['\"]/", $line ) ) {
			return true;
		}

		// Check if line has unmatched quotes (incomplete strings).
		$single_quotes = substr_count( $line, "'" ) - substr_count( $line, "\\'" );
		$double_quotes = substr_count( $line, '"' ) - substr_count( $line, '\\"' );

		if ( ( 0 !== $single_quotes % 2 ) || ( 0 !== $double_quotes % 2 ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Fix a single comment line based on pattern matches
	 *
	 * @param string $line Original line.
	 * @param string $pattern Regex pattern used.
	 * @param array  $matches Regex matches.
	 * @return string Fixed line.
	 */
	private function fix_comment_line( $line, $pattern, $matches ) {
		// Extract comment text based on pattern type.
		if ( preg_match( '/^(\s*)\/\//', $line ) ) {
			// Single line // comment.
			$indent         = $matches[1];
			$comment_text   = trim( $matches[2] );
			$trailing_space = $matches[3] ?? '';

			$fixed_comment = $this->add_punctuation( $comment_text );
			return $indent . '// ' . $fixed_comment . $trailing_space;

		} elseif ( preg_match( '/^(\s*)#/', $line ) ) {
			// Single line # comment.
			$indent         = $matches[1];
			$comment_text   = trim( $matches[2] );
			$trailing_space = $matches[3] ?? '';

			$fixed_comment = $this->add_punctuation( $comment_text );
			return $indent . '# ' . $fixed_comment . $trailing_space;

		} elseif ( preg_match( '/^(.+?)(\s+)\/\//', $line ) ) {
			// Inline // comment after code.
			$code           = $matches[1];
			$spacing        = $matches[2];
			$comment_text   = trim( $matches[3] );
			$trailing_space = $matches[4] ?? '';

			$fixed_comment = $this->add_punctuation( $comment_text );
			return $code . $spacing . '// ' . $fixed_comment . $trailing_space;

		} elseif ( preg_match( '/^(.+?)(\s+)#/', $line ) ) {
			// Inline # comment after code.
			$code           = $matches[1];
			$spacing        = $matches[2];
			$comment_text   = trim( $matches[3] );
			$trailing_space = $matches[4] ?? '';

			$fixed_comment = $this->add_punctuation( $comment_text );
			return $code . $spacing . '# ' . $fixed_comment . $trailing_space;
		}

		return $line;
	}

	/**
	 * Add proper punctuation to comment text if missing
	 *
	 * @param string $comment_text Comment text to check.
	 * @return string Comment text with proper punctuation.
	 */
	private function add_punctuation( $comment_text ) {
		$comment_text = trim( $comment_text );

		if ( empty( $comment_text ) ) {
			return $comment_text;
		}

		// Check if comment already ends with proper punctuation.
		$last_char         = substr( $comment_text, -1 );
		$valid_punctuation = array( '.', '!', '?', ':', ';' );

		if ( in_array( $last_char, $valid_punctuation, true ) ) {
			return $comment_text;
		}

		// Special cases where we don't add punctuation.
		$no_punctuation_patterns = array(
			'/^TODO\s*:?$/i',
			'/^FIXME\s*:?$/i',
			'/^NOTE\s*:?$/i',
			'/^@\w+/',  // Annotations like @param, @return.
			'/^\$\w+/', // Variable references.
			'/^https?:\/\//', // URLs.
			'/^\w+\(\)$/', // Function calls like function().
			'/^[A-Z_][A-Z0-9_]*$/', // Constants like CONSTANT_NAME.
			'/^\d+(\.\d+)?$/', // Numbers.
			'/^[\w\-\.]+\.php$/', // File names.
		);

		foreach ( $no_punctuation_patterns as $pattern ) {
			if ( preg_match( $pattern, $comment_text ) ) {
				return $comment_text;
			}
		}

		// Add period for regular comments.
		return $comment_text . '.';
	}
}

// Execute the script.
if ( 'cli' !== php_sapi_name() ) {
	die( 'This script must be run from the command line.' );
}

$fixer = new InlineCommentFixer();

// Process all provided paths (skip script name in argv[0]).
$cli_paths = array_slice( $argv, 1 );

if ( empty( $cli_paths ) ) {
	// No arguments provided, process current directory.
	exit( $fixer->run() );
}

$exit_code = 0;
foreach ( $cli_paths as $cli_path ) {
	echo "\n=== Processing: $cli_path ===\n";
	$result = $fixer->run( $cli_path );
	if ( 0 !== $result ) {
		$exit_code = $result;
	}
}

exit( $exit_code );
