<?php
/**
 * Private on-disk storage for the invoice PDFs Kontor returns.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Invoices;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps downloaded invoices out of reach of anyone who was not sent them.
 *
 * An invoice carries a customer's name, address and what they bought, so it cannot
 * go into the media library: everything under wp-content/uploads is served straight
 * off disk by the web server, and an attachment URL is readable by anyone who has it
 * — no login, no order, no check of any kind.
 *
 * Three things keep these files private, because none of them is sufficient alone:
 *
 * 1. The directory name carries a random suffix generated once per site, so it
 *    cannot be guessed or read out of the plugin's source.
 * 2. It holds an .htaccess, a web.config and an index.php, which stop Apache and IIS
 *    serving or listing it.
 * 3. Every filename carries its own random component, so knowing the directory and
 *    an invoice number is still not enough to construct a URL.
 *
 * Reading a file back always goes through Download, which checks who is asking.
 *
 * Nginx reads none of those guard files, and WordPress gives a plugin no portable
 * place to write that a web server will not serve. On such a host only the random
 * names are protecting the invoices: a PDF fetched at its own address under uploads
 * is handed over without Download ever being reached. A shop that wants the directory
 * closed adds a deny rule for it in the server configuration; nothing here can do
 * that on its behalf.
 *
 * The plugin used to probe for exactly that once a day and warn on the settings
 * screen. It no longer does. The check cost a loopback request the site made to
 * itself and a permanent probe file sitting among the invoices, to report a condition
 * whose realistic exposure is an address escaping through a server log or a backup —
 * and its notice was read as saying the download links were insecure, which they are
 * not: a link carries the order key and is meant to work for whoever holds it.
 * Stating the limit here, once, is worth more than restating it daily on a screen.
 */
class Storage {

	/**
	 * Option holding this site's invoice directory name.
	 */
	const OPTION_DIR = 'wksync_invoice_dir';

	/**
	 * Prefix of the directory created inside the uploads folder.
	 */
	const DIR_PREFIX = 'woo-kontor-sync-invoices-';

	/**
	 * Length of the random suffix on the directory name and on each file.
	 */
	const RANDOM_LENGTH = 16;

	/**
	 * The bytes every PDF starts with.
	 *
	 * Kontor's reply is base64 that decodes to something; that something is only
	 * worth writing to disk if it is actually a PDF.
	 */
	const PDF_MAGIC = '%PDF-';

	/**
	 * Store one invoice PDF.
	 *
	 * @param string $contents Raw PDF bytes.
	 * @param string $number   Invoice number, used to make the filename readable.
	 * @return string|WP_Error Path relative to the uploads directory, or WP_Error on failure.
	 */
	public static function put( $contents, $number ) {
		if ( ! self::is_pdf( $contents ) ) {
			return new WP_Error(
				'wksync_invoice_not_a_pdf',
				__( 'Kontor returned a document that is not a PDF.', 'woo-kontor-sync-pro' )
			);
		}

		$directory = self::directory();

		if ( is_wp_error( $directory ) ) {
			return $directory;
		}

		$filesystem = self::filesystem();

		if ( ! $filesystem ) {
			return new WP_Error(
				'wksync_invoice_no_filesystem',
				__( 'The invoice could not be written: WordPress has no direct filesystem access.', 'woo-kontor-sync-pro' )
			);
		}

		$filename = self::filename( $number );

		if ( ! $filesystem->put_contents( $directory['path'] . $filename, $contents, FS_CHMOD_FILE ) ) {
			return new WP_Error(
				'wksync_invoice_write_failed',
				__( 'The invoice could not be written to the uploads directory.', 'woo-kontor-sync-pro' )
			);
		}

		return $directory['name'] . '/' . $filename;
	}

	/**
	 * Resolve a stored relative path to a readable file.
	 *
	 * The path comes out of order meta, so it is treated as untrusted: a value
	 * carrying "../" would otherwise read anything the web server can. Both sides are
	 * resolved with realpath() and the result has to still be inside the invoice
	 * directory.
	 *
	 * @param string $relative Path relative to the uploads directory.
	 * @return string|WP_Error Absolute path to an existing file, or WP_Error.
	 */
	public static function resolve( $relative ) {
		$relative = ltrim( (string) $relative, '/' );

		if ( '' === $relative ) {
			return new WP_Error( 'wksync_invoice_missing', __( 'No invoice file is recorded.', 'woo-kontor-sync-pro' ) );
		}

		$directory = self::directory( false );

		if ( is_wp_error( $directory ) ) {
			return $directory;
		}

		$root = realpath( untrailingslashit( $directory['path'] ) );
		$file = realpath( self::uploads_path() . $relative );

		if ( false === $root || false === $file || ! is_file( $file ) ) {
			return new WP_Error( 'wksync_invoice_missing', __( 'The invoice file is no longer on disk.', 'woo-kontor-sync-pro' ) );
		}

		if ( ! str_starts_with( $file, trailingslashit( $root ) ) ) {
			return new WP_Error( 'wksync_invoice_outside', __( 'The recorded invoice path is not inside the invoice directory.', 'woo-kontor-sync-pro' ) );
		}

		return $file;
	}

	/**
	 * Whether a stored invoice is still readable.
	 *
	 * @param string $relative Path relative to the uploads directory.
	 * @return bool True when the file exists and is inside the invoice directory.
	 */
	public static function exists( $relative ) {
		return ! is_wp_error( self::resolve( $relative ) );
	}

	/**
	 * The invoice directory, created and protected on first use.
	 *
	 * @param bool $create Whether to create the directory when it is absent.
	 * @return array|WP_Error Array with "name" and "path" keys, or WP_Error on failure.
	 */
	public static function directory( $create = true ) {
		$uploads = self::uploads_path();

		if ( '' === $uploads ) {
			return new WP_Error(
				'wksync_invoice_no_uploads',
				__( 'The uploads directory is not writable, so invoices cannot be stored.', 'woo-kontor-sync-pro' )
			);
		}

		$name = (string) get_option( self::OPTION_DIR, '' );

		if ( '' === $name ) {
			/*
			 * Generated once and remembered. Regenerating it would strand every invoice
			 * already downloaded, because the paths on the orders point at the old name.
			 */
			$name = self::DIR_PREFIX . wp_generate_password( self::RANDOM_LENGTH, false, false );

			update_option( self::OPTION_DIR, $name, false );
		}

		$path = trailingslashit( $uploads . $name );

		if ( ! is_dir( $path ) ) {
			if ( ! $create ) {
				return new WP_Error( 'wksync_invoice_no_directory', __( 'The invoice directory does not exist.', 'woo-kontor-sync-pro' ) );
			}

			if ( ! wp_mkdir_p( $path ) ) {
				return new WP_Error(
					'wksync_invoice_mkdir_failed',
					__( 'The invoice directory could not be created inside the uploads folder.', 'woo-kontor-sync-pro' )
				);
			}
		}

		if ( $create ) {
			self::protect( $path );
		}

		return array(
			'name' => $name,
			'path' => $path,
		);
	}

	/**
	 * Write the files that stop a web server serving the directory.
	 *
	 * Rewritten whenever they are missing rather than only at creation, so a
	 * directory restored from a backup that dropped dotfiles is protected again on
	 * the next sync.
	 *
	 * @param string $path Absolute path to the invoice directory.
	 * @return void
	 */
	protected static function protect( $path ) {
		$filesystem = self::filesystem();

		if ( ! $filesystem ) {
			return;
		}

		$guards = array(
			// Apache 2.4 and 2.2 respectively; an unknown directive is ignored.
			'.htaccess'  => "Require all denied\n<IfModule !mod_authz_core.c>\n\tDeny from all\n</IfModule>\n",
			'web.config' => "<configuration>\n\t<system.webServer>\n\t\t<authorization>\n\t\t\t<deny users=\"*\"/>\n\t\t</authorization>\n\t</system.webServer>\n</configuration>\n",
			'index.php'  => "<?php\n// Silence is golden.\n",
		);

		foreach ( $guards as $file => $contents ) {
			if ( ! $filesystem->exists( $path . $file ) ) {
				$filesystem->put_contents( $path . $file, $contents, FS_CHMOD_FILE );
			}
		}

		/*
		 * The probe the exposure check used to fetch. Nothing writes it any more, but
		 * protect() only ever added files, so on a site that ran an earlier version it
		 * would sit among the invoices for good. It is ours, and it is litter.
		 */
		$probe = $path . 'protection-probe.pdf';

		if ( $filesystem->exists( $probe ) ) {
			$filesystem->delete( $probe );
		}
	}

	/**
	 * Build the filename for one invoice.
	 *
	 * The invoice number is kept so a file is identifiable on disk, and a random
	 * component is appended so the full path cannot be worked out from the number
	 * alone.
	 *
	 * @param string $number Invoice number.
	 * @return string Filename, including the extension.
	 */
	protected static function filename( $number ) {
		$number = sanitize_file_name( (string) $number );
		$number = '' === $number ? 'document' : $number;

		return sprintf( 'invoice-%s-%s.pdf', $number, wp_generate_password( self::RANDOM_LENGTH, false, false ) );
	}

	/**
	 * Whether a blob of bytes is a PDF.
	 *
	 * @param string $contents Bytes to check.
	 * @return bool True when the file starts with the PDF signature.
	 */
	protected static function is_pdf( $contents ) {
		return is_string( $contents ) && str_starts_with( $contents, self::PDF_MAGIC );
	}

	/**
	 * The uploads directory, with a trailing slash.
	 *
	 * @return string Absolute path, or an empty string when uploads are unusable.
	 */
	protected static function uploads_path() {
		$uploads = wp_upload_dir( null, false );

		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return '';
		}

		return trailingslashit( $uploads['basedir'] );
	}

	/**
	 * The WordPress filesystem abstraction, initialised on first use.
	 *
	 * Used in preference to the raw PHP file functions so hosts that route writes
	 * through something other than the local disk keep working.
	 *
	 * @return \WP_Filesystem_Base|null The filesystem, or null when it is unavailable.
	 */
	protected static function filesystem() {
		global $wp_filesystem;

		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';

			WP_Filesystem();
		}

		return $wp_filesystem ? $wp_filesystem : null;
	}
}
