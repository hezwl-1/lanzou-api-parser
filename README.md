# 蓝奏云合集与文件解析接口

这是一个独立提取出来的 PHP 版蓝奏云解析接口项目，用于获取蓝奏云文件夹合集列表，以及解析蓝奏云单文件下载信息。

项目内置以下能力：

- 合集/文件夹列表获取
- 单文件链接解析
- 手机 UA 兜底获取源码
- `acw_sc__v2` 反爬 Cookie 自动计算
- JSON 接口输出
- CORS 跨域响应头
- 成功结果文件缓存

## 文件结构

```text
api/common.php                          公共函数、CORS、JSON 输出
api/software/files.php                  合集/文件夹列表接口
api/lanzou/resolve.php                  单文件解析接口
cache/software_files/                   合集列表缓存目录
cache/lanzou_resolve/                   单文件解析缓存目录
examples/collection_b0w9n5hxa_page1.json 测试响应样例
t.php                                   合集解析核心
la.php                                  单文件解析入口桥接
lanzou_parser.php                       单文件解析核心
```

## 环境要求

- PHP 7.4 或更高版本
- PHP curl 扩展
- PHP json 扩展
- `cache/` 目录需要可写权限

检查 curl 扩展：

```bash
php -m | grep curl
```

## 部署方法

把本仓库所有文件上传到 PHP 网站根目录，例如：

```text
/网站根目录/api/software/files.php
/网站根目录/api/lanzou/resolve.php
/网站根目录/t.php
/网站根目录/la.php
/网站根目录/lanzou_parser.php
/网站根目录/cache/
```

设置缓存目录权限：

```bash
chmod -R 755 cache
```

如果 PHP 运行用户无法写入缓存，可以改成：

```bash
chmod -R 775 cache
```

## 接口一：获取蓝奏云合集列表

请求地址：

```text
GET /api/software/files.php?url=蓝奏云合集链接&page=页码
```

请求示例：

```bash
curl "http://你的域名/api/software/files.php?url=https%3A%2F%2Fwwbvf.lanzouu.com%2Fb0w9n5hxa&page=1"
```

返回示例：

```json
{
  "success": true,
  "file_count": 35,
  "current_page": 1,
  "files": [
    {
      "index": 1,
      "name": "Eggplant Video",
      "url": "https://wwbvf.lanzu.com/i5Pc33zy71ef",
      "time": "3 天前",
      "size": "31.2 M"
    }
  ]
}
```

## 合集接口原理

蓝奏云文件夹页面源码中通常会包含 AJAX 请求参数：

```text
lx, fid, uid, puid, pg, rep, t, k, up, vip, webfoldersign
```

接口会自动从页面源码里的 `data: { ... }` 中提取这些参数，然后请求：

```text
/filemoreajax.php?file=FID
```

如果桌面 UA 获取不到正常源码，接口会自动切换手机 UA 重新请求源码。

如果服务器拿到的是蓝奏云 `arg1` 反爬挑战页，接口会自动计算 `acw_sc__v2` Cookie，然后带 Cookie 再请求一次真实源码。

## 接口二：蓝奏云单文件解析

请求地址：

```text
GET /api/lanzou/resolve.php?url=蓝奏云单文件链接
```

请求示例：

```bash
curl "http://你的域名/api/lanzou/resolve.php?url=https%3A%2F%2Fwwbvf.lanzouu.com%2Fi5Pc33zy71ef"
```

常见返回字段：

```text
filename         文件名
file_size        文件大小
download_url     下载地址
description      文件描述
response_time_ms 请求耗时
http_status      HTTP 状态码
```

## 测试效果

### 合集解析完整测试

测试合集链接：

```text
https://wwbvf.lanzouu.com/b0w9suulg
```

请求示例：

```bash
curl "http://你的域名/api/software/files.php?url=https%3A%2F%2Fwwbvf.lanzouu.com%2Fb0w9suulg&page=1"
```

接口实际返回概要：

```json
{
  "success": true,
  "file_count": 18,
  "current_page": 1
}
```

第一条文件数据：

```json
{
  "index": 1,
  "name": "山楂4K影视",
  "url": "https://wwbvf.lanzouu.com/ilR8J3wkmxmd",
  "time": "20 天前",
  "size": "91.1 M"
}
```

合集解析完整返回文件：

```text
examples/collection_b0w9suulg_page1.json
```

### 单文件解析完整测试

测试单文件链接：

```text
https://wwbvf.lanzouu.com/ilR8J3wkmxmd
```

请求示例：

```bash
curl "http://你的域名/api/lanzou/resolve.php?url=https%3A%2F%2Fwwbvf.lanzouu.com%2FilR8J3wkmxmd"
```

接口实际返回概要：

```json
{
  "http_status": 200,
  "filename": "山楂4K影视.apk",
  "file_size": "91.1 M",
  "download_url": "https://..."
}
```

单文件解析完整返回文件：

```text
examples/single_ilR8J3wkmxmd.json
```

### 旧测试样例

另一个已测试合集链接：

```text
https://wwbvf.lanzouu.com/b0w9n5hxa
```

接口实际返回：

```json
{
  "success": true,
  "file_count": 35,
  "current_page": 1
}
```

前 5 条测试数据：

```text
1. Eggplant Video - 31.2 M - 3 天前
2. 趣闲赚 - 5.6 M - 3 天前
3. 赏帮赚 - 5.7 M - 3 天前
4. Pineapple - 32.6 M - 3 天前
5. Adult Bilibili - 31.2 M - 3 天前
```

完整测试响应文件：

```text
examples/collection_b0w9n5hxa_page1.json
```

## 缓存说明

- 合集列表缓存目录：`cache/software_files/`
- 单文件解析缓存目录：`cache/lanzou_resolve/`
- 合集接口默认缓存 180 秒
- 单文件接口默认缓存 300 秒

如果实时解析失败，但本地存在最近一次成功缓存，合集接口会返回旧缓存，并带上：

```json
{
  "cache_fallback": true
}
```

## 注意事项

- 蓝奏云页面结构可能变化，如果字段或反爬逻辑变了，需要同步更新解析核心。
- 分享链接失效、取消分享或需要访问权限时，接口无法生成真实列表。
- 建议保留缓存，减少请求次数，提高接口稳定性。
