<?php
/**
 * 蓝奏云单文件解析器
 *
 * 支持新版单文件页脚本直链结构：
 *   var vkjxld = 'https://.../file/';
 *   var hyggid = '?...';
 *
 * 同时保留旧版 id="downurl" 链接解析作为 fallback。
 */

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    header('Content-Type: application/json; charset=utf-8');
    la_handle_request();
}

function la_handle_request(): void
{
    $inputUrl = isset($_GET['url']) ? trim((string) $_GET['url']) : '';
    la_output_json(la_resolve_url($inputUrl));
}

function la_resolve_url(string $inputUrl): array
{
    if ($inputUrl === '') {
        return [
            'error' => '缺少url参数',
        ];
    }

    if (!function_exists('curl_init')) {
        return [
            'error' => '服务器未启用 curl 扩展',
        ];
    }

    $decodedUrl = la_decode_url($inputUrl);
    $processedUrl = $decodedUrl;

    if (!filter_var($processedUrl, FILTER_VALIDATE_URL)) {
        return [
            'error' => 'URL格式无效',
            'original_url' => $inputUrl,
            'decoded_url' => $decodedUrl,
            'processed_url' => $processedUrl,
        ];
    }

    $startedAt = microtime(true);
    $fetchResult = la_fetch_html($processedUrl);
    $elapsedMs = round((microtime(true) - $startedAt) * 1000, 2);
    $html = (string) ($fetchResult['body'] ?? '');

    $result = [
        'response_time_ms' => $elapsedMs,
        'content_length' => strlen($html),
        'http_status' => (int) ($fetchResult['http_status'] ?? 0),
        'filename' => '',
        'file_size' => '',
        'download_url' => '',
        'description' => '',
    ];

    if (!empty($fetchResult['error'])) {
        $result['error'] = $fetchResult['error'];
        return $result;
    }

    if ($html === '') {
        $result['error'] = '蓝奏云页面内容为空';
        return $result;
    }

    $result = array_merge($result, la_extract_file_meta($html));

    $directUrl = la_extract_script_direct_url($html);
    if ($directUrl !== '') {
        $result['download_url'] = $directUrl;
        return $result;
    }

    $downUrl = la_extract_downurl_href($html, $processedUrl);
    if ($downUrl !== '' && !la_is_transfer_page_url($downUrl)) {
        $result['download_url'] = $downUrl;
        return $result;
    }

    if ($downUrl !== '') {
        $secondFetchResult = la_fetch_html($downUrl, $processedUrl);
        $secondHtml = (string) ($secondFetchResult['body'] ?? '');
        $result['content_length'] += strlen($secondHtml);
        $result['http_status'] = (int) ($secondFetchResult['http_status'] ?? 0);

        if (!empty($secondFetchResult['error'])) {
            $result['error'] = $secondFetchResult['error'];
            return $result;
        }

        if ($secondHtml === '') {
            $result['error'] = '蓝奏云中转页面内容为空';
            return $result;
        }

        foreach (la_extract_file_meta($secondHtml) as $key => $value) {
            if (($result[$key] ?? '') === '' && $value !== '') {
                $result[$key] = $value;
            }
        }

        $secondDirectUrl = la_extract_script_direct_url($secondHtml);
        if ($secondDirectUrl !== '') {
            $result['download_url'] = $secondDirectUrl;
            return $result;
        }
    }

    $result['error'] = '无法解析下载地址';
    return $result;
}

function la_decode_url(string $url): string
{
    $decoded = $url;
    $previous = '';

    while ($decoded !== $previous) {
        $previous = $decoded;
        $next = urldecode($decoded);
        if ($next === $decoded || $next === '') {
            break;
        }
        $decoded = $next;
    }

    return $decoded;
}

function la_fetch_html(string $url, string $referer = ''): array
{
    $result = la_fetch_html_once($url, $referer);
    $cookieValue = la_extract_acw_sc_v2_cookie((string) ($result['body'] ?? ''));

    if ($cookieValue === '' || !empty($result['error'])) {
        return $result;
    }

    return la_fetch_html_once($url, $referer, 'acw_sc__v2=' . $cookieValue);
}

function la_fetch_html_once(string $url, string $referer = '', string $cookie = ''): array
{
    $host = (string) parse_url($url, PHP_URL_HOST);
    $headers = [
        'Host: ' . $host,
        'Connection: keep-alive',
        'Cache-Control: max-age=0',
        'Upgrade-Insecure-Requests: 1',
        'User-Agent: Mozilla/5.0 (Linux; Android 12; Mobile) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0 Mobile Safari/537.36',
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
        'Sec-Fetch-Site: none',
        'Sec-Fetch-Mode: navigate',
        'Sec-Fetch-Dest: document',
        'Accept-Language: zh-CN,zh;q=0.9,en-US;q=0.8,en;q=0.7',
    ];

    if ($cookie !== '') {
        $headers[] = 'Cookie: ' . $cookie;
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_ENCODING => '',
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);

    if ($referer !== '') {
        curl_setopt($ch, CURLOPT_REFERER, $referer);
    }

    $body = curl_exec($ch);
    $error = curl_error($ch);
    $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (PHP_VERSION_ID < 80500) {
        curl_close($ch);
    }

    return [
        'body' => $body === false ? '' : (string) $body,
        'http_status' => $httpStatus,
        'error' => $body === false ? ($error ?: 'curl_exec failed') : '',
    ];
}

function la_extract_acw_sc_v2_cookie(string $html): string
{
    if (stripos($html, 'acw_sc__v2') === false || stripos($html, 'arg1') === false) {
        return '';
    }

    if (!preg_match('/\barg1\s*=\s*([\'"])([a-f0-9]{40})\1/i', $html, $matches)) {
        return '';
    }

    $arg1 = strtoupper((string) $matches[2]);
    $order = la_extract_acw_sc_v2_order($html);
    if ($order === []) {
        $order = [0xf, 0x23, 0x1d, 0x18, 0x21, 0x10, 0x1, 0x26, 0xa, 0x9, 0x13, 0x1f, 0x28, 0x1b, 0x16, 0x17, 0x19, 0xd, 0x6, 0xb, 0x27, 0x12, 0x14, 0x8, 0xe, 0x15, 0x20, 0x1a, 0x2, 0x1e, 0x7, 0x4, 0x11, 0x5, 0x3, 0x1c, 0x22, 0x25, 0xc, 0x24];
    }

    $key = '3000176000856006061501533003690027800375';
    $reordered = '';
    foreach ($order as $position) {
        $index = $position - 1;
        if ($index < 0 || $index >= strlen($arg1)) {
            return '';
        }
        $reordered .= $arg1[$index];
    }

    $cookie = '';
    $limit = min(strlen($reordered), strlen($key));
    for ($i = 0; $i + 1 < $limit; $i += 2) {
        $left = hexdec(substr($reordered, $i, 2));
        $right = hexdec(substr($key, $i, 2));
        $part = dechex($left ^ $right);
        if (strlen($part) === 1) {
            $part = '0' . $part;
        }
        $cookie .= $part;
    }

    return preg_match('/^[a-f0-9]{40}$/', $cookie) ? $cookie : '';
}

function la_extract_acw_sc_v2_order(string $html): array
{
    if (!preg_match('/\bvar\s+\w+\s*=\s*\[((?:\s*(?:0x[a-f0-9]+|\d+)\s*,?)+)\]\s*,\s*\w+\s*=/i', $html, $matches)) {
        return [];
    }

    preg_match_all('/0x[a-f0-9]+|\d+/i', (string) $matches[1], $numberMatches);
    $order = [];
    foreach ($numberMatches[0] as $number) {
        $order[] = stripos($number, '0x') === 0 ? hexdec($number) : (int) $number;
    }

    return count($order) === 40 ? $order : [];
}

function la_extract_file_meta(string $html): array
{
    $meta = [
        'filename' => '',
        'file_size' => '',
        'description' => '',
    ];

    $filenamePatterns = [
        '/<div[^>]*class=["\'][^"\']*\bmd\b[^"\']*["\'][^>]*>\s*(.*?)(?:<span\b|<\/div>)/is',
        '/<div[^>]*class=["\'][^"\']*\bappname\b[^"\']*["\'][^>]*>(.*?)<\/div>/is',
        '/<div[^>]*class=["\'][^"\']*\bn_box_3fn\b[^"\']*["\'][^>]*>(.*?)<\/div>/is',
        '/<title[^>]*>(.*?)<\/title>/is',
    ];
    $meta['filename'] = la_first_clean_match($html, $filenamePatterns);
    $meta['filename'] = preg_replace('/\s*-\s*蓝奏云\s*$/u', '', $meta['filename']) ?? $meta['filename'];

    $sizePatterns = [
        '/下载\s*[\(（]\s*([^)）]+?)\s*[\)）]/iu',
        '/<span[^>]*class=["\'][^"\']*\bmtt\b[^"\']*["\'][^>]*>\s*[\(（]?\s*([^()（）<]+?)\s*[\)）]?\s*<\/span>/is',
        '/文件大小：?\s*([^<\r\n]+)\s*(?:<br|<\/)/iu',
    ];
    $meta['file_size'] = la_first_clean_match($html, $sizePatterns);

    $descriptionPatterns = [
        '/<div[^>]*class=["\'][^"\']*\bmdo\b[^"\']*["\'][^>]*>(.*?)<\/div>/is',
        '/<div[^>]*class=["\'][^"\']*\bappdes\b[^"\']*["\'][^>]*>(.*?)<\/div>/is',
        '/<div[^>]*class=["\'][^"\']*\bn_box_des\b[^"\']*["\'][^>]*>(.*?)<\/div>/is',
    ];
    $meta['description'] = la_first_clean_match($html, $descriptionPatterns);

    if (preg_match('/<div[^>]*class=["\'][^"\']*\bappico\b[^"\']*["\'][^>]*>.*?url\((.*?)\)/is', $html, $matches)) {
        $meta['icon_url'] = trim(la_decode_js_string(trim($matches[1], '\'" ')));
    } elseif (preg_match('/<div[^>]*class=["\'][^"\']*\bappico\b[^"\']*["\'][^>]*url\((.*?)\)/is', $html, $matches)) {
        $meta['icon_url'] = trim(la_decode_js_string(trim($matches[1], '\'" ')));
    }

    return $meta;
}

function la_extract_script_direct_url(string $html): string
{
    $base = la_extract_js_var($html, 'vkjxld');
    $query = la_extract_js_var($html, 'hyggid');

    if ($base === '' || $query === '') {
        return '';
    }

    $base = la_decode_js_string($base);
    $query = la_decode_js_string($query);
    $lanosso = la_extract_lanosso_suffix($html);

    if (!preg_match('/^https?:\/\//i', $base)) {
        return '';
    }

    $url = $base . $query;
    if ($lanosso !== '') {
        $url .= $lanosso;
    }

    return preg_match('/^https?:\/\//i', $url) ? $url : '';
}

function la_extract_lanosso_suffix(string $html): string
{
    $assignments = la_extract_js_var_assignments($html, 'lanosso');

    foreach ($assignments as $assignment) {
        $lanosso = la_decode_js_string($assignment);
        if ($lanosso !== '') {
            return $lanosso;
        }
    }

    return '';
}

function la_extract_downurl_href(string $html, string $baseUrl): string
{
    if (!preg_match('/<a\b(?=[^>]*\bid=["\']downurl["\'])(?=[^>]*\bhref=["\']([^"\']+)["\'])[^>]*>/i', $html, $matches)) {
        return '';
    }

    $path = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return la_absolute_url($path, $baseUrl);
}

function la_is_transfer_page_url(string $url): bool
{
    $path = (string) parse_url($url, PHP_URL_PATH);
    return preg_match('~/tp/~i', $path) === 1;
}

function la_extract_js_var(string $html, string $name): string
{
    $assignments = la_extract_js_var_assignments($html, $name);
    return (string) ($assignments[0] ?? '');
}

function la_extract_js_var_assignments(string $html, string $name): array
{
    $pattern = '/(?<![\w$.])(?:(?:var|let|const)\s+)?' . preg_quote($name, '/') . '\s*=\s*([\'"])((?:\\\\.|(?!\1).)*)\1\s*;?/s';
    if (!preg_match_all($pattern, $html, $matches, PREG_SET_ORDER)) {
        return [];
    }

    $assignments = [];
    foreach ($matches as $match) {
        $assignments[] = (string) ($match[2] ?? '');
    }

    return $assignments;
}

function la_decode_js_string(string $value): string
{
    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = str_replace('\/', '/', $value);
    return trim(stripcslashes($value));
}

function la_first_clean_match(string $html, array $patterns): string
{
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $html, $matches) && isset($matches[1])) {
            $text = la_clean_html_text((string) $matches[1]);
            if ($text !== '') {
                return $text;
            }
        }
    }

    return '';
}

function la_clean_html_text(string $html): string
{
    $text = preg_replace('/<br\s*\/?>/i', "\n", $html) ?? $html;
    $text = preg_replace('/<\/(p|div|li)>/i', "\n", $text) ?? $text;
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/[ \t\r\f\v]+/', ' ', $text) ?? $text;
    $text = preg_replace('/ *\n+ */', "\n", $text) ?? $text;
    $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
    return trim($text);
}

function la_absolute_url(string $path, string $baseUrl): string
{
    $path = trim($path);
    if ($path === '') {
        return '';
    }

    if (preg_match('/^https?:\/\//i', $path)) {
        return $path;
    }

    $scheme = (string) (parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https');
    $host = (string) parse_url($baseUrl, PHP_URL_HOST);
    if ($host === '') {
        return '';
    }

    if (strpos($path, '//') === 0) {
        return $scheme . ':' . $path;
    }

    if ($path[0] === '/') {
        return $scheme . '://' . $host . $path;
    }

    $basePath = (string) parse_url($baseUrl, PHP_URL_PATH);
    $directory = rtrim(str_replace('\\', '/', dirname($basePath)), '/');
    return $scheme . '://' . $host . ($directory === '' ? '' : '/' . ltrim($directory, '/')) . '/' . $path;
}

function la_output_json(array $data): void
{
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
