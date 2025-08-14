<?php
/**
 * Copyright (C) 2017-2024 thirty bees
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@thirtybees.com so we can send you a copy immediately.
 *
 * @author    thirty bees <modules@thirtybees.com>
 * @copyright 2017-2024 thirty bees
 * @license   Academic Free License (AFL 3.0)
 */

namespace CollectLogsModule;

/**
 * Class GithubIssueFormatter
 * Formats log entries as markdown suitable for GitHub issues and sanitizes sensitive information.
 */
class GithubIssueFormatter
{
    /**
     * @var TransformMessage
     */
    private $transform;

    /**
     * @var string
     */
    private $adminSeg;    // e.g. "admin123"

    /**
     * @var string
     */
    private $adminFsPath; // e.g. "/var/www/html/admin123"

    /**
     * GithubIssueFormatter constructor.
     *
     * @param TransformMessage $transform
     * @param string|null $adminSeg
     * @param string|null $adminFsPath
     */
    public function __construct(TransformMessage $transform, string $adminSeg, string $adminFsPath)
    {
        $this->transform    = $transform;
        $this->adminSeg     = $adminSeg;
        $this->adminFsPath  = $adminFsPath;
    }

    /**
     * Sanitize string by removing sensitive information such as tokens, passwords, emails, cookies and admin path
     *
     * @param string $text
     * @return string
     */
    public function sanitize(string $text): string
	{
		// 1) run custom transform
		$text = $this->transform->transform($text);

		// 2) normalize newlines and decode escaped \n
		$text = str_replace(["\r\n", "\r"], "\n", $text);
		$text = preg_replace('/\\\\r?\\\\n/', "\n", $text);

		// 3) hide admin path + basename using detected admin folder
		$text = $this->maskAdminFolder($text);

		// Keep scheme and replace domain
		$text = preg_replace('#\b(https?://)[^/\s]+#i', '$1[domain]', $text);

		// Also handle bare hosts without scheme (avoid emails with (?<!@))
		//$text = preg_replace('#(?<!@)\b[a-z0-9.-]+\.[a-z]{2,}(?::\d+)?(?=/|\b)#i', '[domain]', $text);

		// 4) URLs: ?token=..., &password=...
		$text = preg_replace('/([?&])(token|secure_key|password|passwd|pwd|email)=([^&\s]+)/i', '$1$2=[removed]', $text);

		// 5) PHP dumps: "token" => "..."
		$text = preg_replace('/"(token|secure_key|password|passwd|pwd|email)"\s*=>\s*".*?"/i', '"$1"=>"[removed]"', $text);

		// 6) Bracket/colon lists: [token]: "..."
		$text = preg_replace('/\[(token|secure_key|password|passwd|pwd|email)\]\s*:\s*"[^"]*"/i', '[$1]: "[removed]"', $text);
		$text = preg_replace('/\[(remote_addr)\]\s*:\s*"[^"]*"/i', '[$1]: "[ip]"', $text);

		// 7) Key: value forms (but NOT inside URLs)
		$text = preg_replace('/(?<![?&])\b(token|secure_key|password|passwd|pwd|email)\b\s*[:=]\s*\S+/i', '$1: [removed]', $text);
		$text = preg_replace('/(?<![?&])\bremote_addr\b\s*[:=]\s*\S+/i', 'remote_addr: [ip]', $text);

		// 8) HTTP Cookie header line
		$text = preg_replace('/^Cookie:\s*[^\r\n]*/im', 'Cookie: [removed]', $text);

		// 9) Emails anywhere
		$text = preg_replace('/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i', '[email]', $text);

		// 10) IPv4 dotted anywhere
		$text = preg_replace('/\b(?:\d{1,3}\.){3}\d{1,3}\b/', '[ip]', $text);

		// 11) Generic fallback — only if not already sanitized
		$text = preg_replace('/(?<!\[)\b(password|passwd|token|secure_key|secret)\b(?!\s*[:=]?\s*\[removed\])(?:\s*[:=]\s*[^\s\]"]+)?/i', '$1: [removed]', $text);

		// 12) Collapse accidental duplicates
		$text = preg_replace('/(\[(?:removed|ip)\])(?:\s*\1)+/i', '$1', $text);

		return $text;
	}




    /**
     * Format log and extras into markdown string suitable for GitHub issue
     *
     * @param array $log
     * @param array $extras
     * @return string
     */
    public function format(array $log, array $extras): string
    {
        $type = $log['type'] ?? '';

        $md  = "# Error report\n\n";
        $md .= '**Type:** ' . $this->sanitize($type) . "\n\n";

        $message = $this->sanitize($log['generic_message'] ?? '');
        if (!empty($log['sample_message']) && $log['sample_message'] !== ($log['generic_message'] ?? '')) {
            $message .= "\n\n" . $this->sanitize($log['sample_message']);
        }
        $md .= "**Message:**\n\n";
        $md .= "```\n" . $message . "\n```\n\n";

        $location = !empty($log['real_file'])
            ? ($log['real_file'] . ':' . ($log['real_line'] ?? ''))
            : (($log['file'] ?? '') . ':' . ($log['line'] ?? ''));
        $md .= '**Location:** `' . $this->sanitize($location) . "`\n\n";

        foreach ($extras as $extra) {
			$label   = $this->sanitize($extra['label'] ?? '');
			$content = $this->sanitize($extra['content'] ?? '');
			$md     .= '### ' . $label . "\n\n";
			$md     .= "```\n" . $content . "\n```\n\n";
		}

        return $md;
    }

	/** Replace any occurrence of the BO admin folder with [admin] */
	private function maskAdminFolder(string $text): string
	{
		// If nothing is known, use a conservative heuristic once.
		if (!$this->adminSeg && !$this->adminFsPath) {
			return preg_replace(
				'~(?<=^|[^\w])(admin(?:-dev|[0-9A-Za-z_-]*))(?=(?:/|\\\\|$|\?|\#|\())~i',
				'[admin]',
				$text
			);
		}

		// 1) Fast path: exact filesystem path(s) — no regex
		if ($this->adminFsPath) {
			$fs = str_replace('\\', '/', $this->adminFsPath);
			$variants = [$fs];

			$real = @realpath($this->adminFsPath);
			if ($real) {
				$real = str_replace('\\', '/', $real);
				if (strcasecmp($real, $fs) !== 0) {
					$variants[] = $real;
				}
			}

			// forward-slash variants
			$text = str_ireplace($variants, '[admin]', $text);

			// backslash variants (Windows-style)
			$backVariants = [];
			foreach ($variants as $v) {
				$backVariants[] = str_replace('/', '\\', $v);
			}
			$text = str_ireplace($backVariants, '[admin]', $text);
		}

		// 2) Replace the basename only when it’s a path/URL segment
		if ($this->adminSeg) {
			$base = $this->adminSeg;
			$text = preg_replace(
				'~(?<=^|[^\w])' . preg_quote($base, '~') . '(?=(?:/|\\\\|$|\?|\#|\())~i',
				'[admin]',
				$text
			);
		}

		// 3) Elided stacktraces: “…/adminXYZ/”
		$patternBase = $this->adminSeg ? preg_quote($this->adminSeg, '~') : 'admin(?:-dev|[0-9A-Za-z_-]*)';
		$text = preg_replace(
			'~(?:(?<=…)|(?<=\.{3}))(?:(?:/|\\\\))' . $patternBase . '(?=(?:/|\\\\))~i',
			'/[admin]',
			$text
		);

		return $text;
	}

}