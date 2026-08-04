<?php
declare(strict_types=1);

require_once __DIR__ . '/../common.php';

$url = trim((string) ($_GET['url'] ?? $_GET['collection_url'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));

if ($url === '') {
    api_error('?? url ???', 422);
}

if (!function_exists('curl_init')) {
    api_error('?????? curl ?????????????', 500);
}

$cacheDir = api_project_path('cache/software_files');
$cacheFile = $cacheDir . DIRECTORY_SEPARATOR . hash('sha256', $url . '|' . $page) . '.json';
$cacheTtl = 180;
$staleCached = null;

if (is_file($cacheFile)) {
    $cached = file_get_contents($cacheFile);
    if ($cached !== false && $cached !== '') {
        $cachedDecoded = json_decode($cached, true);
        if (is_array($cachedDecoded) && !empty($cachedDecoded['success']) && isset($cachedDecoded['files']) && is_array($cachedDecoded['files'])) {
            $staleCached = $cachedDecoded;
        }
        if ((time() - filemtime($cacheFile)) < $cacheTtl) {
            header('X-Api-Cache: HIT');
            echo $cached;
            exit;
        }
    }
}

$_GET['url'] = $url;
$_GET['page'] = (string) $page;
$_GET['action'] = 'filelist';
$_GET['debug'] = 'false';

ob_start();
require api_project_path('t.php');
$body = (string) ob_get_clean();
$decoded = json_decode($body, true);

$liveOk = is_array($decoded) && !empty($decoded['success']) && isset($decoded['files']) && is_array($decoded['files']);
$liveUnavailable = is_array($decoded) && !empty($decoded['link_unavailable']);

if ((!$liveOk || $liveUnavailable) && is_array($staleCached)) {
    $staleCached['cache_fallback'] = true;
    $staleCached['fallback_reason'] = $decoded['error'] ?? '??????????????????';
    header('X-Api-Cache: STALE');
    echo json_encode($staleCached, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($liveOk) {
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }
    @file_put_contents($cacheFile, $body, LOCK_EX);
}

header('X-Api-Cache: MISS');
echo $body;
