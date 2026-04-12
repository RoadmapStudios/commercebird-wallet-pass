#!/usr/bin/env php
<?php
/**
 * PHP script to fix Yoda conditions in PHP files within specified directories
 *
 * Usage: php fix-yoda-directories.php directory1 directory2
 * Example: php fix-yoda-directories.php includes admin
 *
 * If no directories are provided, it defaults to 'includes' and 'admin/includes'.
 *
 * @package CommerceBird
 */

/**
 * Enhanced script to fix Yoda conditions in PHP files
 * Usage: php fix-yoda-directories.php directory1 directory2
 * Example: php fix-yoda-directories.php includes admin
 */
class YodaFixer {
	private $fixed_count     = 0;
	private $processed_files = 0;

	public function __construct( $paths ) {
		if ( empty( $paths ) ) {
			echo "Usage: php fix-yoda-directories.php directory1 directory2\n";
			echo "Example: php fix-yoda-directories.php includes admin\n";
			exit( 1 );
		}

		foreach ( $paths as $path ) {
			if ( ! file_exists( $path ) ) {
				echo "Error: Path '$path' does not exist.\n";
				exit( 1 );
			}
			$this->processPath( $path );
		}

		echo "\n=== Summary ===\n";
		echo "- Files processed: {$this->processed_files}\n";
		echo "- Files fixed: {$this->fixed_count}\n";
	}

	private function processPath( $path ) {
		if ( is_file( $path ) ) {
			$this->processFile( $path );
		} elseif ( is_dir( $path ) ) {
			$this->processDirectory( $path );
		}
	}

	private function processDirectory( $dir ) {
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir ) );

		foreach ( $iterator as $file ) {
			if ( $file->isFile() && $file->getExtension() === 'php' ) {
				$this->processFile( $file->getPathname() );
			}
		}
	}

	private function processFile( $filePath ) {
		++$this->processed_files;

		if ( ! is_readable( $filePath ) ) {
			echo "Warning: Cannot read file '$filePath'\n";
			return;
		}

		$content         = file_get_contents( $filePath );
		$originalContent = $content;

		// Fix Yoda conditions.
		$content = $this->fixYodaConditions( $content );

		if ( $content !== $originalContent ) {
			if ( file_put_contents( $filePath, $content ) ) {
				echo "Fixed Yoda conditions in: $filePath\n";
				++$this->fixed_count;
			} else {
				echo "Error: Could not write to file '$filePath'\n";
			}
		}
	}

	private function fixYodaConditions( $content ) {
		// Patterns to fix Yoda conditions.
		$patterns = array(
			// Fix: $var === 0 -> 0 === $var.
			'/(\$[a-zA-Z_][a-zA-Z0-9_]*)\s*(===|==|!==|!=)\s*(0|null|true|false)(?![a-zA-Z0-9_])/',
			// Fix: $var === 'string' -> 'string' === $var.
			'/(\$[a-zA-Z_][a-zA-Z0-9_]*)\s*(===|==|!==|!=)\s*(\'[^\']*\'|"[^"]*")/',
			// Fix: $var === 123 -> 123 === $var (numbers).
			'/(\$[a-zA-Z_][a-zA-Z0-9_]*)\s*(===|==|!==|!=)\s*([0-9]+)(?![a-zA-Z0-9_])/',
		);

		$replacements = array(
			'$3 $2 $1',
			'$3 $2 $1',
			'$3 $2 $1',
		);

		return preg_replace( $patterns, $replacements, $content );
	}
}

// Get command line arguments (excluding script name).
$paths = array_slice( $argv, 1 );

// If no paths provided, use default directories.
if ( empty( $paths ) ) {
	$paths = array( 'includes', 'admin/includes' );
}

new YodaFixer( $paths );
