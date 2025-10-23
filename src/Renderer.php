<?php

namespace Xenos;


/**
 * @internal Only for Router
 */
class Renderer
{

	private static function getPatterns()
	{
		return [
			"/@include\s*\([\"'](.+?)[\"']\)/" => "<?php self::renderBlade('$1') ?>",

			'/@if\s*\((.*?)\)/' => '<?php if ($1) { ?>',
			'/@elseif\s*\((.*?)\)/' => '<?php elseif ($1) { ?>',
			'/@else/' => '<?php } else { ?>',
			'/@endif/' => '<?php } ?>',

			'/@foreach\s*\((.*?)\)/' => '<?php foreach ($1) { ?>',
			'/@endforeach/' => '<?php } ?>',

			'/@for\s*\((.*?)\)/' => '<?php for ($1) { ?>',
			'/@endfor/' => '<?php } ?>',

			'/@while\s*\((.*?)\)/' => '<?php while ($1) { ?>',
			'/@endwhile/' => '<?php } ?>',

			'/@isset\s*\((.*?)\)/' => '<?php $__isset_tmp = $1; if (isset($__isset_tmp)) { ?>',
			'/@endisset/' => '<?php } ?>',

			'/@empty\s*\((.*?)\)/' => '<?php if (empty($1)) { ?>',
			'/@endempty/' => '<?php } ?>',

			'/@php/' => '<?php ',
			'/@endphp/' => ' ?>',

			'/@old\s*\((.+?)\)/' => '<?php echo old($1, \'\') ?>',

			'/@csrf/' => "<input name='_token' value='" . \Xenos\Session::Get('_token') ?? '' . "' hidden>",

			"/@auth/" => "<?php if(Auth::check()) (function() { \$user = Auth::user(); ?>",
			"/@endauth/" => "<?php })(); \$user = null; ?>",
			"/@user/s" => "<?php \$user = Auth::user(); ?>"
		];
	}

	public static function preProcessBlade($file): string
	{
		$content = file_get_contents($file);

		// Remove Blade comments completely (multiline-safe)
		$content = preg_replace('/\{\{--.*?--\}\}/s', '', $content);

		// Recursively process included files
		preg_match_all('/@include\s*\([\'"](.+?)[\'"]\)/', $content, $matches);
		foreach ($matches[1] as $incFile) {
			$incContent = self::preProcessBlade($incFile);
			$content = str_replace("@include('$incFile')", $incContent, $content);
		}

		return $content;
	}

	public static function replaceComments($content): array|string|null
	{
		return preg_replace('/\{\{--.*?--\}\}/s', '', $content);
	}

	public static function replaceErrorBlocks($content): array|string|null
	{
		return preg_replace_callback(
			'/@error\(["\'](.+?)["\']\)(.*?)@enderror/s',
			function ($matches) {
				$key = $matches[1];
				$inner = $matches[2];

				$message = $_SESSION["error"][$key] ?? "";

				if ($message) {
					$inner = preg_replace(
						'/{{\s*\$message\s*}}/',
						htmlspecialchars($message, ENT_QUOTES, 'UTF-8'),
						$inner
					);
					return $inner;
				}
				return '';
			},
			$content
		);
	}

	public static function extractSections(string $content): array
	{
		$sections = [];

		// Handle block sections: @section('name') ... @endsection
		preg_match_all('/@section\s*\(\s*[\'"](.+?)[\'"]\s*\)(.*?)@endsection/s', $content, $blockMatches, PREG_SET_ORDER);
		foreach ($blockMatches as $m) {
			// Only set if not already set by single-line (single-line overrides block if conflict)
			if (!isset($sections[$m[1]])) {
				$sections[$m[1]] = $m[2];
			}
		}

		return $sections;
	}

	/**
	 * Optional helper to remove section blocks from content
	 */
	public static function removeSectionBlocks(string $content): string
	{
		// Remove both single-line and block sections
		$content = preg_replace('/@section\s*\(\s*[\'"].+?[\'"]\s*\).*?@endsection/s', '', $content);

		return $content;
	}

	public static function renderLayout($content, $sections = [])
	{
		// Check if the Blade file extends a parent
		if (preg_match('/@extends\s*\(\s*[\'"](.+?)[\'"]\s*\)/', $content, $match)) {
			$parentFile = $match[1];

			// Preprocess parent BEFORE using it
			$parentContent = self::preProcessBlade($parentFile);

			// Replace yields with child sections
			foreach ($sections as $name => $sectionContent) {
				$parentContent = preg_replace(
					'/@yield\s*\(\s*[\'"]' . preg_quote($name, '/') . '[\'"]\s*\)/',
					$sectionContent,
					$parentContent
				);
			}

			// Replace default yields
			$parentContent = preg_replace_callback(
				'/@yield\s*\(\s*[\'"](.+?)[\'"]\s*,\s*[\'"](.*?)[\'"]\s*\)/',
				function ($m) use ($sections) {
					return $sections[$m[1]] ?? $m[2];
				},
				$parentContent
			);

			return $parentContent;
		}

		// If no @extends, just return the content as-is
		return $content;
	}

	public static function replaceBladeDirectives($content)
	{
		$patterns = self::getPatterns();
		// return preg_replace(array_keys($patterns), array_values($patterns), $content);
		return preg_replace(array_keys($patterns), array_values($patterns), $content);
	}

	public static function replaceBladeEchoes($content)
	{
		return preg_replace_callback('/\{\{\s*(.+?)\s*\}\}/', function ($m) {
			$expr = trim($m[1]);
			return "<?php try { echo htmlspecialchars({$expr}, ENT_QUOTES, 'UTF-8'); } catch (\\Throwable \$e) { echo ''; } ?>";
		}, $content);
	}


	/**
	 * @deprecated This method is deprecated. Use normal Blade includes instead.
	 */
	public static function renderBladeComponents(string $content): string
	{
		if (trigger_error(__METHOD__ . " is deprecated and current on development", E_USER_DEPRECATED))
			return $content;

		$renderCallback = function ($matches) use (&$renderCallback) {
			$tag = $matches[1];
			$attrString = $matches[2] ?? '';
			$innerContent = trim($matches[3] ?? '');

			$componentFile = "app/components/{$tag}.blade.php";

			if (!file_exists($componentFile)) {
				throw new \Exception("Component file '{$tag}.blade.php' not found in app/components/");
			}

			// Preprocess component content
			$componentContent = self::preProcessBlade($componentFile);

			// Extract default attributes inside component
			preg_match_all('/\{\{\s*\$(\w+)\s*\?\?\s*[\'"](.*?)[\'"]\s*\}\}/', $componentContent, $defaultMatches, PREG_SET_ORDER);
			$defaults = [];
			foreach ($defaultMatches as $dm) {
				$defaults[$dm[1]] = $dm[2];
			}

			// Parse user attributes from tag
			preg_match_all('/(\w+)=[\'"]([^\'"]+)[\'"]/', $attrString, $attrMatches, PREG_SET_ORDER);
			$userAttrs = [];
			foreach ($attrMatches as $am) {
				$userAttrs[$am[1]] = $am[2];
			}

			// Merge attributes (user overrides default; concat class/style)
			$attrs = $defaults;
			foreach ($userAttrs as $key => $value) {
				if (isset($attrs[$key]) && in_array($key, ['class', 'style'])) {
					$attrs[$key] .= ' ' . $value;
				} else {
					$attrs[$key] = $value;
				}
			}

			// Replace placeholders inside component if exist
			foreach ($attrs as $key => $value) {
				$componentContent = preg_replace('/\{\{\s*\$' . preg_quote($key, '/') . '\s*\?\?.*?\}\}/', $value, $componentContent);
				$componentContent = str_replace("{{ \$$key }}", $value, $componentContent);
			}

			// Inject user attributes if not present in component tag
			preg_match('/^<([a-z0-9\-]+)(.*?)>/', $componentContent, $compTagMatch);
			if (!empty($compTagMatch)) {
				$existingAttrs = $compTagMatch[2] ?? '';
				$attrsStr = '';
				foreach ($attrs as $key => $value) {
					// Only add if not already present
					if (!preg_match('/' . preg_quote($key) . '=/i', $existingAttrs)) {
						$attrsStr .= " $key=\"$value\"";
					}
				}
				// Inject merged attributes into opening tag
				$componentContent = preg_replace('/^<([a-z0-9\-]+)(.*?)>/', '<$1' . $existingAttrs . $attrsStr . '>', $componentContent, 1);
			}

			// Automatically inject inner content
			if (strpos($componentContent, '{{ $slot }}') !== false) {
				$componentContent = str_replace('{{ $slot }}', $innerContent ?: "Automatically add here", $componentContent);
			} else {
				// Append inner content after opening tag if $slot not present
				$componentContent = preg_replace('/(<[a-zA-Z0-9\-]+\b[^>]*>)/', '$1' . ($innerContent ?: "Automatically add here"), $componentContent, 1);
			}

			// Recursively render nested components
			if (preg_match('/<([A-Z][A-Za-z0-9]*)\s*(.*?)>(.*?)<\/\1>/s', $componentContent)) {
				$componentContent = preg_replace_callback(
					'/<([A-Z][A-Za-z0-9]*)\s*(.*?)>(.*?)<\/\1>/s',
					$renderCallback,
					$componentContent
				);
			}

			return $componentContent;
		};

		return preg_replace_callback(
			'/<([A-Z][A-Za-z0-9]*)\s*(.*?)>(.*?)<\/\1>/s',
			$renderCallback,
			$content
		);
	}

	public static function removeLeftOvers(string $content): string
	{
		// Remove any @yield('something') without fallback
		$content = preg_replace('/@yield\s*\(\s*[\'"].+?[\'"]\s*\)/', '', $content);

		// Optionally, also remove empty lines left behind
		$content = preg_replace("/^[ \t]*\r?\n/m", '', $content);

		return $content;
	}

	public static function render(string $content, string $file = 'inline'): void
	{

		$debug = true;

		if (!$debug) {
			echo '' . $content . '';
			return;
		}

		try {
			eval('?>' . $content);
		} catch (\Throwable $e) {
			$line = $e->getLine(); // line inside eval
			$message = $e->getMessage();

			// Optionally show file + line info
			echo "<pre style='color:red'>";
			echo "Error in file: $file\n";
			echo "Line: $line\n";
			echo "Message: $message\n";
			echo "</pre>";
		}
	}

	public static function view(string $file, array $vars = [])
	{
		extract($vars);
		$content  = self::preProcessBlade($file);
		$sections = self::extractSections($content);
		$content  = self::removeSectionBlocks($content);
		$content  = self::renderLayout($content, $sections);
		$content  = self::replaceErrorBlocks($content);

		// Components renderer
		// $content = self::renderBladeComponents($content);

		// Remove multiple blank lines
		// For now lets test if no have this
		// $content = preg_replace("/(\r?\n){2,}/", "\n", $content);

		$content = self::replaceBladeDirectives($content);

		// 🔹 Replace {{ $var }} with echo
		$content = self::replaceBladeEchoes($content);

		// Remove any leftover if not compiled yet
		$content = self::removeLeftOvers($content);

		// 🔹 Evaluate the final compiled PHP
		self::render($content);
	}
}
