<?php
declare(strict_types=1);

require_once __DIR__ . '/../common.php';

$url = trim((string) ($_GET['url'] ?? ''));

if ($url === '') {
    api_error('?? url ???', 422);
}

if (!function_exists('curl_init')) {
    api_error('?????? curl ?????????????', 500);
}

$cacheDir = api_project_path('cache/lanzou_resolve');
$cacheFile = $cacheDir . DIRECTORY_SEPARATOR . hash('sha256', $url) . '.json';
$cacheTtl = 300;

if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTtl) {
    $cached = file_get_contents($cacheFile);
    if ($cached !== false && $cached !== '') {
        $cachedDecoded = json_decode($cached, true);
        if (is_array($cachedDecoded) && empty($cachedDecoded['error']) && !empty($cachedDecoded['download_url']) && !lanzou_resolve_is_transfer_url((string) $cachedDecoded['download_url'])) {
            header('X-Api-Cache: HIT');
            echo $cached;
            exit;
        }
        @unlink($cacheFile);
    }
}

require_once api_project_path('la.php');

if (!function_exists('la_resolve_url')) {
    api_error('???????? la_resolve_url ?????', 500);
}

$decoded = la_resolve_url($url);
$body = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

if ($body === false) {
    api_error('????????????', 500);
}

if (empty($decoded['error']) && !empty($decoded['download_url']) && !lanzou_resolve_is_transfer_url((string) $decoded['download_url'])) {
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }
    @file_put_contents($cacheFile, $body, LOCK_EX);
}

header('X-Api-Cache: MISS');
echo $body;

function lanzou_resolve_is_transfer_url(string $url): bool
{
    $path = (string) parse_url($url, PHP_URL_PATH);
    return preg_match('~/tp/~i', $path) === 1;
}
