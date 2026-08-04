# Lanzou API Parser

Standalone PHP interfaces extracted from a production project for Lanzou cloud parsing.

Features:

- Collection/folder file list API.
- Single-file resolve API.
- Mobile User-Agent fallback when desktop source does not contain AJAX parameters.
- Automatic `acw_sc__v2` cookie solving for Lanzou anti-bot challenge pages.
- JSON API responses with CORS headers.
- Simple file cache for successful responses.

## Directory layout

```text
api/common.php
api/software/files.php
api/lanzou/resolve.php
cache/software_files/
cache/lanzou_resolve/
examples/collection_b0w9n5hxa_page1.json
t.php
la.php
lanzou_parser.php
```

## Requirements

- PHP 7.4+
- PHP curl extension
- PHP json extension
- Writable `cache/` directory

Check curl:

```bash
php -m | grep curl
```

## Deployment

Upload all files to your PHP site root:

```text
/site-root/api/software/files.php
/site-root/api/lanzou/resolve.php
/site-root/t.php
/site-root/la.php
/site-root/lanzou_parser.php
/site-root/cache/
```

Set cache permission:

```bash
chmod -R 755 cache
# Use 775 if your PHP user cannot write cache files.
chmod -R 775 cache
```

## API 1: Collection file list

### Endpoint

```text
GET /api/software/files.php?url=LanzouCollectionUrl&page=1
```

### Example

```bash
curl "http://your-domain/api/software/files.php?url=https%3A%2F%2Fwwbvf.lanzouu.com%2Fb0w9n5hxa&page=1"
```

### Response shape

```json
{
  "success": true,
  "file_count": 35,
  "current_page": 1,
  "files": [
    {
      "index": 1,
      "name": "Eggplant Video",
      "url": "https://wwbvf.lanzouu.com/i5Pc33zy71ef",
      "time": "3 days ago",
      "size": "31.2 M"
    }
  ]
}
```

### How it works

The parser reads the Lanzou page source and extracts AJAX parameters from `data: { ... }`:

```text
lx, fid, uid, puid, pg, rep, t, k, up, vip, webfoldersign
```

Then it posts those parameters to:

```text
/filemoreajax.php?file=FID
```

If desktop HTML does not include the needed parameters, the parser retries with a mobile User-Agent. If the server receives an `arg1` challenge page, it calculates the `acw_sc__v2` cookie and requests the page again.

## API 2: Single-file resolve

### Endpoint

```text
GET /api/lanzou/resolve.php?url=LanzouSingleFileUrl
```

### Example

```bash
curl "http://your-domain/api/lanzou/resolve.php?url=https%3A%2F%2Fwwbvf.lanzouu.com%2Fi5Pc33zy71ef"
```

### Common response fields

```text
filename, file_size, download_url, description, response_time_ms, http_status
```

## Tested result

Tested collection URL:

```text
https://wwbvf.lanzouu.com/b0w9n5hxa
```

Actual API result:

```json
{
  "success": true,
  "file_count": 35,
  "current_page": 1
}
```

First 5 records returned during testing:

```text
1. Eggplant Video - 31.2 M - 3 days ago
2. Qu Xian Zhuan - 5.6 M - 3 days ago
3. Shang Bang Zhuan - 5.7 M - 3 days ago
4. Pineapple - 32.6 M - 3 days ago
5. Adult Bilibili - 31.2 M - 3 days ago
```

Full sample response:

```text
examples/collection_b0w9n5hxa_page1.json
```

## Cache behavior

- Collection cache directory: `cache/software_files/`
- Single-file cache directory: `cache/lanzou_resolve/`
- Collection cache TTL: 180 seconds
- Single-file cache TTL: 300 seconds
- If live collection parsing fails but a previous successful cache exists, the API can return stale cache with:

```json
{
  "cache_fallback": true
}
```

## Notes

- Lanzou page structure may change. Update the parser if parameter names or anti-bot logic changes.
- Expired or permission-limited share links cannot be resolved into a real list.
- Keep caching enabled to reduce requests and improve stability.
