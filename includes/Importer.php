<?php
namespace H2WPI;

if ( ! defined( 'ABSPATH' ) ) exit;

class Importer {

	public static function init() {}

	public static function import_single_file(string $file, array $opts): array {
		$parsed = self::parse_html($file);
		if ( ! $parsed ) {
			return ['ok'=>false,'msg'=>"Failed to parse: $file"];
		}

		$title   = $parsed['title'] ?: self::title_from_filename($file);
		$content = $parsed['body_html'] ?: $parsed['raw_html'];
		$mtime   = @filemtime($file);
		$rel     = ltrim(str_replace(realpath($opts['base_path']), '', realpath($file)), DIRECTORY_SEPARATOR);

		// import local assets & rewrite links
		$media_map = [];
		$content   = self::import_and_rewrite_assets($content, $opts['base_path'], $opts['base_url'], $media_map, !empty($opts['dry_run']));

		$postarr = [
			'post_type'    => $opts['post_type'],
			'post_status'  => $opts['status'],
			'post_author'  => (int)$opts['author'],
			'post_title'   => $title,
			'post_content' => $content,
			'post_name'    => sanitize_title(preg_replace('/\.(html|htm)$/i','',$rel)),
		];

		if ( ! empty($opts['keep_dates']) && $mtime ) {
			$gd = gmdate('Y-m-d H:i:s', $mtime);
			$postarr['post_date']     = $gd;
			$postarr['post_date_gmt'] = $gd;
		}

		if ( ! empty($opts['dry_run']) ) {
			return ['ok'=>true,'msg'=>"[DRY] Would import “$title” from $rel"];
		}

		$post_id = wp_insert_post($postarr, true);
		if ( is_wp_error($post_id) ) {
			return ['ok'=>false,'msg'=>"Insert failed for $rel: ".$post_id->get_error_message()];
		}

		if ( ! empty($opts['category']) && $opts['post_type']==='post' ) {
			$term = get_term_by('slug', $opts['category'], 'category');
			if ( $term && ! is_wp_error($term) ) {
				wp_set_post_terms($post_id, [(int)$term->term_id], 'category', true);
			}
		}

		if ( ! empty($opts['set_featured']) && ! empty($media_map['__first_image_id']) ) {
			set_post_thumbnail($post_id, (int)$media_map['__first_image_id']);
		}

		// optional traceability
		update_post_meta($post_id, '_h2wpi_source', $rel);

		return ['ok'=>true,'msg'=>"Created #$post_id “$title”"];
	}

	/* ---------- Helpers ---------- */

	private static function parse_html(string $file): ?array {
		$html = @file_get_contents($file);
		if ($html === false) return null;

		libxml_use_internal_errors(true);
		$dom = new \DOMDocument();
		$dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_HTML_NOIMPLIED|LIBXML_HTML_NODEFDTD);
		libxml_clear_errors();

		$title = '';
		$body_html = '';

		$titles = $dom->getElementsByTagName('title');
		if ($titles->length) $title = trim($titles->item(0)->textContent);
		if (! $title) {
			$h1s = $dom->getElementsByTagName('h1');
			if ($h1s->length) $title = trim($h1s->item(0)->textContent);
		}

		$bodies = $dom->getElementsByTagName('body');
		if ($bodies->length) {
			$body   = $bodies->item(0);
			$inner  = '';
			foreach ($body->childNodes as $child) $inner .= $dom->saveHTML($child);
			$body_html = $inner;
		}

		return ['title'=>$title,'body_html'=>$body_html,'raw_html'=>$html];
	}

	private static function title_from_filename(string $path): string {
		$base = basename($path);
		$base = preg_replace('/\.(html|htm)$/i','',$base);
		$base = str_replace(['-','_'],' ',$base);
		return ucwords(trim($base));
	}

	private static function is_relative(string $url): bool {
		return ( ! preg_match('#^([a-z]+:)?//#i',$url) && ! str_starts_with($url,'data:') );
	}

	private static function import_and_rewrite_assets(string $html, string $base_path, string $base_url, array &$media_map, bool $dry): string {
		if ($html==='') return $html;

		libxml_use_internal_errors(true);
		$dom = new \DOMDocument();
		$dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_HTML_NOIMPLIED|LIBXML_HTML_NODEFDTD);
		libxml_clear_errors();

		$first_image_id = null;

		// <img>
		$imgs = $dom->getElementsByTagName('img');
		foreach (iterator_to_array($imgs) as $img) {
			$src = $img->getAttribute('src');
			if (! $src) continue;
			$new = self::maybe_import_file($src, $base_path, $base_url, $dry);
			if ($new && ! empty($new['url'])) {
				$img->setAttribute('src', $new['url']);
				if (! $first_image_id && ! empty($new['attachment_id'])) $first_image_id = (int)$new['attachment_id'];
			}
		}

		// <a href> to media (pdf/images)
		$links = $dom->getElementsByTagName('a');
		foreach (iterator_to_array($links) as $a) {
			$href = $a->getAttribute('href');
			if (! $href) continue;
			$ext = strtolower(pathinfo(parse_url($href, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
			if ( in_array($ext, ['pdf','jpg','jpeg','png','gif','webp','svg'], true) ) {
				$new = self::maybe_import_file($href, $base_path, $base_url, $dry);
				if ($new && ! empty($new['url'])) $a->setAttribute('href', $new['url']);
			}
		}

		if ($first_image_id) $media_map['__first_image_id'] = $first_image_id;

		return $dom->saveHTML();
	}

	private static function maybe_import_file(string $url, string $base_path, string $base_url, bool $dry): ?array {
		$path_part = parse_url($url, PHP_URL_PATH);
		$local_fs  = null;

		if ($path_part && self::is_relative($url)) {
			$local_fs = realpath(rtrim($base_path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.ltrim($path_part,'/\\'));
		} elseif ($path_part && $base_url && str_starts_with($url, rtrim($base_url,'/'))) {
			$rel = ltrim(substr($url, strlen(rtrim($base_url,'/'))), '/\\');
			$local_fs = realpath(rtrim($base_path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$rel);
		}

		if ($local_fs && is_file($local_fs) && is_readable($local_fs)) {
			if ($dry) return ['url'=>$url];
			$att_id = self::sideload($local_fs);
			if ( is_wp_error($att_id) ) return ['url'=>$url];
			return ['attachment_id'=>$att_id, 'url'=>wp_get_attachment_url($att_id)];
		}

		return ['url'=>$url]; // leave externals as-is
	}

	private static function sideload(string $abs_path) {
		$filename = wp_basename($abs_path);
		$filetype = wp_check_filetype($filename);
		$bits = wp_upload_bits($filename, null, @file_get_contents($abs_path));
		if ( ! empty($bits['error']) ) return new \WP_Error('upload_error',$bits['error']);
		$attachment = [
			'post_mime_type' => $filetype['type'] ?: 'application/octet-stream',
			'post_title'     => sanitize_text_field(preg_replace('/\.[^.]+$/','',$filename)),
			'post_content'   => '',
			'post_status'    => 'inherit',
		];
		$att_id = wp_insert_attachment($attachment, $bits['file']);
		if ( is_wp_error($att_id) ) return $att_id;
		$meta = wp_generate_attachment_metadata($att_id, $bits['file']);
		wp_update_attachment_metadata($att_id, $meta);
		return $att_id;
	}
}