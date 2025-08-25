<?php
namespace H2WPI;

if ( ! defined( 'ABSPATH' ) ) exit;

class Admin {

	public static function init() {
		add_action('admin_menu',            [__CLASS__, 'menu']);
		add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);

		// AJAX batch processors for the progress UI
		add_action('wp_ajax_h2wpi_start_job', [__CLASS__, 'ajax_start_job']);
		add_action('wp_ajax_h2wpi_run_batch', [__CLASS__, 'ajax_run_batch']);

		// NEW: admin-post handler so form submit never hits the fragile tools/admin URL directly
		add_action('admin_post_h2wpi_prepare', [__CLASS__, 'handle_prepare']);
	}

	public static function menu() {
		// Tools → HTML → WP Importer
		add_submenu_page(
			'tools.php',
			'HTML → WP Importer',
			'HTML → WP Importer',
			'manage_options',
			'h2wpi',
			[__CLASS__, 'screen']
		);
	}

	public static function assets( $hook ) {
		// Load only on our page
		if ( $hook !== 'tools_page_h2wpi' && strpos($hook, '_page_h2wpi') === false ) return;

		// Keep these enqueues; files not included here per your request
		wp_enqueue_style ('h2wpi-admin', H2WPI_URL.'assets/admin.css', [], H2WPI_VER);
		wp_enqueue_script('h2wpi-admin', H2WPI_URL.'assets/js/admin.js', ['jquery'], H2WPI_VER, true);
		wp_localize_script('h2wpi-admin', 'H2WPI', [
			'ajax'  => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('h2wpi_ajax'),
		]);
	}

	public static function screen() {
		if ( ! current_user_can('manage_options') ) wp_die('Nope.');

		$users      = get_users(['fields'=>['ID','display_name']]);
		$post_types = get_post_types(['public'=>true], 'objects');
		$cats       = get_terms(['taxonomy'=>'category','hide_empty'=>false]);

		$job_id = isset($_GET['job']) ? sanitize_text_field( wp_unslash( $_GET['job'] ) ) : '';

		?>
		<div class="wrap">
			<h1>HTML → WP Importer</h1>
			<p>Import static HTML into WordPress (no CLI required). Upload a ZIP or point to a server folder.</p>

			<div class="h2wpi-grid">
				<form id="h2wpi-form"
					  method="post"
					  enctype="multipart/form-data"
					  action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="h2wpi_prepare" />
					<?php wp_nonce_field('h2wpi_form','h2wpi_form_nonce'); ?>

					<h2>Source</h2>
					<p><label><strong>Upload ZIP</strong> (contains .html/.htm and assets):<br>
						<input type="file" name="h2wpi_zip" accept=".zip">
					</label></p>
					<p><em>— or —</em></p>
					<p><label><strong>Server folder path</strong> (absolute or relative to WP root):<br>
						<input type="text" name="h2wpi_path" class="regular-text" placeholder="/html or /absolute/path/to/html" />
					</label></p>

					<h2>Options</h2>
					<table class="form-table">
						<tr>
							<th>Post Type</th>
							<td>
								<select name="post_type">
									<?php foreach($post_types as $pt){ ?>
										<option value="<?php echo esc_attr($pt->name); ?>"><?php echo esc_html($pt->labels->singular_name); ?></option>
									<?php } ?>
								</select>
							</td>
						</tr>
						<tr>
							<th>Status</th>
							<td>
								<select name="status">
									<option value="draft">Draft</option>
									<option value="publish">Publish</option>
									<option value="private">Private</option>
								</select>
							</td>
						</tr>
						<tr>
							<th>Author</th>
							<td>
								<select name="author">
									<?php foreach($users as $u){ ?>
										<option value="<?php echo (int)$u->ID; ?>"><?php echo esc_html($u->display_name); ?></option>
									<?php } ?>
								</select>
							</td>
						</tr>
						<tr>
							<th>Category (posts only)</th>
							<td>
								<select name="category">
									<option value="">— none —</option>
									<?php foreach($cats as $c){ ?>
										<option value="<?php echo esc_attr($c->slug); ?>"><?php echo esc_html($c->name); ?></option>
									<?php } ?>
								</select>
							</td>
						</tr>
						<tr>
							<th>Base URL</th>
							<td><input type="url" name="base_url" class="regular-text" placeholder="https://example.com"></td>
						</tr>
						<tr>
							<th>Keep file modified dates</th>
							<td><label><input type="checkbox" name="keep_dates" value="1"> Use file mtime as post date</label></td>
						</tr>
						<tr>
							<th>Set featured image</th>
							<td><label><input type="checkbox" name="set_featured" value="1"> First image becomes featured</label></td>
						</tr>
						<tr>
							<th>Dry run</th>
							<td><label><input type="checkbox" name="dry_run" value="1"> Parse only (no posts/media)</label></td>
						</tr>
					</table>

					<p><button type="submit" class="button button-primary">Prepare Import</button></p>
				</form>

				<div id="h2wpi-progress" class="<?php echo $job_id ? '' : 'hidden'; ?>">
					<h2>Progress</h2>
					<div class="h2wpi-bar"><span></span></div>
					<p class="h2wpi-meta"><span class="done">0</span>/<span class="total">0</span> processed • <span class="created">0</span> created • <span class="skipped">0</span> skipped</p>
					<pre id="h2wpi-log"></pre>
				</div>
			</div>
		</div>

		<?php if ( $job_id ) : ?>
			<script>
			window.addEventListener('load', function(){
				if (document.getElementById('h2wpi-progress')) {
					H2WPI_Progress.start('<?php echo esc_js($job_id); ?>');
				}
			});
			</script>
			<div class="notice notice-success"><p>Import prepared. Processing now…</p></div>
		<?php endif;
	}

	/**
	 * admin-post handler: validate, prepare job, then redirect back to our Tools page with ?job=
	 */
	public static function handle_prepare() {
		if ( ! current_user_can('manage_options') ) {
			wp_die('Insufficient permissions.');
		}
		if ( ! isset($_POST['h2wpi_form_nonce']) || ! wp_verify_nonce($_POST['h2wpi_form_nonce'], 'h2wpi_form') ) {
			wp_die('Security check failed.');
		}

		// Resolve source: uploaded ZIP or server path (absolute or relative to ABSPATH)
		$upload_dir = wp_upload_dir();
		$source_dir = '';
		$cleanup    = [];

		// If ZIP uploaded, require ZipArchive or bail
		if ( ! empty($_FILES['h2wpi_zip']['name']) ) {
			if ( ! class_exists('ZipArchive') ) {
				wp_die('ZIP uploads require the ZipArchive PHP extension. Enable it or use the Server folder path option.');
			}
			$file = $_FILES['h2wpi_zip'];
			if ( $file['error'] !== UPLOAD_ERR_OK ) {
				wp_die('Upload failed: ' . (int)$file['error']);
			}
			$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
			if ( $ext !== 'zip' ) {
				wp_die('Please upload a .zip file.');
			}
			require_once ABSPATH . 'wp-admin/includes/file.php';
			$overrides = ['test_form'=>false];
			$uploaded = wp_handle_upload($file, $overrides);
			if ( ! empty($uploaded['error']) ) {
				wp_die( esc_html($uploaded['error']) );
			}
			$zip_path = $uploaded['file'];

			$tmp_target = trailingslashit($upload_dir['basedir']).'h2wpi/'.wp_generate_uuid4();
			wp_mkdir_p($tmp_target);

			$zip = new \ZipArchive();
			if ( $zip->open($zip_path) === true ) {
				$zip->extractTo($tmp_target);
				$zip->close();
				$source_dir = $tmp_target;
				$cleanup[]  = $zip_path;
			} else {
				wp_die('Could not open ZIP.');
			}
		} else {
			$path = isset($_POST['h2wpi_path']) ? trim( wp_unslash($_POST['h2wpi_path']) ) : '';
			if ( $path ) {
				// Accept absolute or relative-to-ABSPATH (e.g., /html or html)
				$candidate = $path;
				if ( '/' === $path[0] ) {
					$candidate = ABSPATH . ltrim($path, '/');
				} else {
					$candidate = ABSPATH . ltrim($path, '/');
				}
				if ( is_dir($candidate) ) {
					$source_dir = realpath($candidate);
				}
			}
			if ( ! $source_dir ) {
				wp_die('Provide a valid server folder path or upload a ZIP.');
			}
		}

		// Scan for .html/.htm
		$rii   = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source_dir, \FilesystemIterator::SKIP_DOTS));
		$files = [];
		foreach($rii as $f){
			if ($f->isDir()) continue;
			$ext = strtolower(pathinfo($f->getFilename(), PATHINFO_EXTENSION));
			if ( in_array($ext, ['html','htm'], true) ) {
				$files[] = $f->getPathname();
			}
		}
		if ( empty($files) ) {
			wp_die('No .html/.htm files found in source.');
		}

		// Build job
		$job_id = 'h2wpi_job_'.wp_generate_uuid4();
		$opts = [
			'post_type'    => sanitize_key($_POST['post_type'] ?? 'post'),
			'status'       => sanitize_key($_POST['status'] ?? 'draft'),
			'author'       => (int)($_POST['author'] ?? get_current_user_id()),
			'category'     => sanitize_key($_POST['category'] ?? ''),
			'base_url'     => esc_url_raw($_POST['base_url'] ?? ''),
			'keep_dates'   => ! empty($_POST['keep_dates']),
			'set_featured' => ! empty($_POST['set_featured']),
			'dry_run'      => ! empty($_POST['dry_run']),
			'base_path'    => $source_dir,
			'cleanup'      => $cleanup,
		];
		$state = [
			'queue'   => array_values($files),
			'done'    => 0,
			'created' => 0,
			'skipped' => 0,
			'log'     => [],
			'total'   => count($files),
		];

		update_option($job_id.'_opts',  $opts,  false);
		update_option($job_id.'_state', $state, false);

		// Redirect back to our page with the job id so the JS can start processing
		wp_safe_redirect( admin_url( 'tools.php?page=h2wpi&job=' . urlencode( $job_id ) ) );
		exit;
	}

	/** AJAX: return the current job state (also proves job exists) */
	public static function ajax_start_job() {
		check_ajax_referer('h2wpi_ajax','nonce');
		if ( ! current_user_can('manage_options') ) wp_send_json_error('perm');
		$job = sanitize_text_field($_POST['job'] ?? '');
		if ( ! $job ) wp_send_json_error('nojob');
		$state = get_option($job.'_state');
		if ( ! $state ) wp_send_json_error('missing');
		wp_send_json_success(['state'=>$state]);
	}

	/** AJAX: process one batch of files */
	public static function ajax_run_batch() {
		check_ajax_referer('h2wpi_ajax','nonce');
		if ( ! current_user_can('manage_options') ) wp_send_json_error('perm');
		$job = sanitize_text_field($_POST['job'] ?? '');
		$batch_size = max(1, min(25, (int)($_POST['batch'] ?? 15)));

		$opts  = get_option($job.'_opts');
		$state = get_option($job.'_state');
		if ( ! $opts || ! $state ) wp_send_json_error('missing');

		$files = array_splice($state['queue'], 0, $batch_size);
		if ( empty($files) ) {
			// cleanup temp zip file(s) if any
			if ( ! empty($opts['cleanup']) ) {
				foreach( (array)$opts['cleanup'] as $c ){
					if ( is_file($c) ) @unlink($c);
				}
			}
			update_option($job.'_state', $state, false);
			wp_send_json_success(['done'=>true,'state'=>$state]);
		}

		require_once ABSPATH.'wp-admin/includes/image.php';
		foreach($files as $path){
			$r = Importer::import_single_file($path, $opts);
			$state['done']++;
			if ( $r['ok'] ) $state['created']++; else $state['skipped']++;
			$state['log'][] = $r['msg'];
		}

		update_option($job.'_state', $state, false);
		wp_send_json_success(['done'=>false,'state'=>$state]);
	}
}