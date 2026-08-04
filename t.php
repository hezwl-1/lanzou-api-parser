<?php
/**
 * 蓝奏云文件列表解析器优化版
 * 重点从JavaScript的function more()中提取参数，并解析变量值
 * 增加数据获取功能和返回原始数据列表功能
 * 优化pg参数默认值为1并确保正确提交POST参数
 * 修复文件列表解析逻辑，与之前分析保持一致
 * 移除源码保存功能，优化性能
 * 修改：pg参数从GET参数page获取，其他参数从第一页获取
 * 修改：将第一页参数保存到url.json文件
 */

class LanzouParserOptimized {
    private $log_file;
    private $debug_mode;
    private $image_domain;
    private $params_dir; // 参数保存目录
    private $firstPageParameters = []; // 存储第一页提取的参数
    
    public function __construct($debug_mode = true) {
        $this->log_file = __DIR__ . '/lanzou_optimized.log';
        $this->debug_mode = $debug_mode;
        $this->image_domain = "image.woozooo"; // 设置默认图片域名
        $this->params_dir = __DIR__ . '/url_params/'; // 参数保存目录
        $this->firstPageParameters = []; // 初始化第一页参数
        $this->initLog();
        $this->initParamsDir();
    }
    
    private function initLog() {
        $timestamp = date('Y-m-d H:i:s');
        $header = "=== 蓝奏云解析开始 {$timestamp} ===\n";
        file_put_contents($this->log_file, $header, FILE_APPEND | LOCK_EX);
    }
    
    private function initParamsDir() {
        if (!is_dir($this->params_dir)) {
            mkdir($this->params_dir, 0755, true);
            $this->log("创建参数保存目录", ['dir' => $this->params_dir]);
        }
    }
    
    private function log($message, $data = null) {
        if (!$this->debug_mode) {
            return '';
        }
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] {$message}\n";
        
        if ($data !== null) {
            $logMessage .= "数据: " . (is_array($data) ? json_encode($data, JSON_UNESCAPED_UNICODE) : $data) . "\n";
        }
        
        file_put_contents($this->log_file, $logMessage, FILE_APPEND | LOCK_EX);
        return $logMessage;
    }
    
    /**
     * 从URL生成文件名
     * 例如：https://rjk65.lanzouw.com/b016kdyc7a -> b016kdyc7a.json
     */
    private function getFilenameFromUrl($url) {
        // 提取URL中的标识符部分
        $parsed = parse_url($url);
        $path = $parsed['path'] ?? '';
        
        // 移除开头的斜杠
        $path = ltrim($path, '/');
        
        // 如果路径为空，尝试从查询参数中获取
        if (empty($path)) {
            // 尝试从file参数获取
            if (isset($parsed['query'])) {
                parse_str($parsed['query'], $queryParams);
                if (isset($queryParams['file'])) {
                    $path = $queryParams['file'];
                }
            }
        }
        
        // 如果路径仍然为空，使用整个URL的md5作为文件名
        if (empty($path)) {
            $filename = md5($url) . '.json';
        } else {
            // 移除可能的查询字符串和额外的路径
            $path = strtok($path, '?');
            $path = strtok($path, '/');
            $path = strtok($path, '&');
            
            // 确保文件名只包含安全字符
            $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $path);
            $filename = $safeName . '.json';
        }
        
        $this->log("生成文件名", ['url' => $url, 'filename' => $filename]);
        return $filename;
    }
    
    /**
     * 保存参数到文件
     */
    private function saveParametersToFile($url, $parameters) {
        $filename = $this->getFilenameFromUrl($url);
        $filepath = $this->params_dir . $filename;
        
        $dataToSave = [
            'url' => $url,
            'parameters' => $parameters,
            'save_time' => date('Y-m-d H:i:s'),
            'timestamp' => time()
        ];
        
        $jsonData = json_encode($dataToSave, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $result = file_put_contents($filepath, $jsonData);
        
        if ($result !== false) {
            $this->log("参数保存到文件成功", [
                'filepath' => $filepath,
                'parameters_count' => count($parameters),
                'parameters_keys' => array_keys($parameters)
            ]);
            return true;
        } else {
            $this->log("参数保存到文件失败", ['filepath' => $filepath]);
            return false;
        }
    }
    
    /**
     * 从文件加载参数
     */
    private function loadParametersFromFile($url) {
        $filename = $this->getFilenameFromUrl($url);
        $filepath = $this->params_dir . $filename;
        
        if (!file_exists($filepath)) {
            $this->log("参数文件不存在", ['filepath' => $filepath]);
            return null;
        }
        
        $jsonData = file_get_contents($filepath);
        $data = json_decode($jsonData, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log("JSON解析失败", [
                'filepath' => $filepath,
                'error' => json_last_error_msg()
            ]);
            return null;
        }
        
        // 检查数据是否过期（超过24小时）
        $currentTime = time();
        $saveTime = $data['timestamp'] ?? 0;
        $timeDiff = $currentTime - $saveTime;
        
        if ($timeDiff > 86400) { // 24小时
            $this->log("参数已过期", [
                'filepath' => $filepath,
                'save_time' => $data['save_time'] ?? 'unknown',
                'hours_old' => round($timeDiff / 3600, 1)
            ]);
            // 不删除文件，但返回null让重新获取
            return null;
        }
        
        $this->log("从文件加载参数成功", [
            'filepath' => $filepath,
            'parameters_count' => count($data['parameters'] ?? []),
            'save_time' => $data['save_time'] ?? 'unknown'
        ]);
        
        return $data['parameters'] ?? null;
    }
    
    /**
     * 检查参数文件是否存在
     */
    private function hasParametersFile($url) {
        $filename = $this->getFilenameFromUrl($url);
        $filepath = $this->params_dir . $filename;
        return file_exists($filepath);
    }
    
    private function solveAcwScV2Cookie($html) {
        if (!is_string($html) || !preg_match("/var\s+arg1\s*=\s*'([0-9A-Fa-f]+)'/", $html, $matches)) {
            return null;
        }
        $arg1 = $matches[1];
        $m = [0xf,0x23,0x1d,0x18,0x21,0x10,0x1,0x26,0xa,0x9,0x13,0x1f,0x28,0x1b,0x16,0x17,0x19,0xd,0x6,0xb,0x27,0x12,0x14,0x8,0xe,0x15,0x20,0x1a,0x2,0x1e,0x7,0x4,0x11,0x5,0x3,0x1c,0x22,0x25,0xc,0x24];
        $p = '3000176000856006061501533003690027800375';
        $q = array_fill(0, count($m), '');
        $chars = str_split($arg1);
        foreach ($chars as $x => $y) {
            foreach ($m as $z => $v) {
                if ($v == $x + 1) {
                    $q[$z] = $y;
                }
            }
        }
        $u = implode('', $q);
        $v = '';
        $len = min(strlen($u), strlen($p));
        for ($i = 0; $i < $len; $i += 2) {
            $a = hexdec(substr($u, $i, 2));
            $b = hexdec(substr($p, $i, 2));
            $hex = dechex($a ^ $b);
            if (strlen($hex) === 1) {
                $hex = '0' . $hex;
            }
            $v .= $hex;
        }
        return $v !== '' ? 'acw_sc__v2=' . $v : null;
    }

    private function httpRequest($url, $postData = null, $headers = []) {
        $this->log("发送HTTP请求", ['url' => $url, 'method' => $postData ? 'POST' : 'GET']);
        $effectiveUserAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';
        foreach ($headers as $headerLine) {
            if (stripos($headerLine, 'User-Agent:') === 0) {
                $effectiveUserAgent = trim(substr($headerLine, strlen('User-Agent:')));
                break;
            }
        }
        
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => $effectiveUserAgent,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_HEADER => false
        ]);
        
        if ($postData !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        }
        
        $defaultHeaders = [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,application/json;q=0.8,*/*;q=0.7',
            'Accept-Language: zh-CN,zh;q=0.9,en;q=0.8',
            'Cache-Control: no-cache',
            'Pragma: no-cache',
        ];
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($defaultHeaders, $headers));
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if (is_string($response) && strlen($response) >= 2 && substr($response, 0, 2) === "\x1f\x8b") {
            $decoded = @gzdecode($response);
            if ($decoded !== false) {
                $response = $decoded;
            }
        }
        $acwCookie = $this->solveAcwScV2Cookie($response);
        if ($acwCookie !== null) {
            $retryHeaders = array_merge($headers, ['Cookie: ' . $acwCookie]);
            $retryCh = curl_init();
            curl_setopt_array($retryCh, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_USERAGENT => $effectiveUserAgent,
                CURLOPT_ENCODING => '',
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_HEADER => false,
                CURLOPT_HTTPHEADER => array_merge($defaultHeaders, $retryHeaders)
            ]);
            if ($postData !== null) {
                curl_setopt($retryCh, CURLOPT_POST, true);
                curl_setopt($retryCh, CURLOPT_POSTFIELDS, $postData);
            }
            $retryResponse = curl_exec($retryCh);
            $retryCode = curl_getinfo($retryCh, CURLINFO_HTTP_CODE);
            $retryError = curl_error($retryCh);
            curl_close($retryCh);
            if (is_string($retryResponse) && strlen($retryResponse) >= 2 && substr($retryResponse, 0, 2) === "\x1f\x8b") {
                $retryDecoded = @gzdecode($retryResponse);
                if ($retryDecoded !== false) {
                    $retryResponse = $retryDecoded;
                }
            }
            if (is_string($retryResponse) && $retryResponse !== '') {
                $response = $retryResponse;
                $httpCode = $retryCode;
                $curlError = $retryError;
            }
        }
        
        $this->log("HTTP??", ['code' => $httpCode, 'length' => is_string($response) ? strlen($response) : 0, 'curl_error' => $curlError]);

        if ($response === false || $response === '') {
            return ['error' => 'HTTP????: empty response' . ($curlError ? ' - ' . $curlError : '')];
        }
        
        if ($httpCode !== 200) {
            return ['error' => 'HTTP????: ' . $httpCode];
        }
        
        return $response;
    }
    
    /**
     * 解析JavaScript变量值
     */
    private function resolveVariableValue($html, $variableName) {
        $this->log("解析变量值", ['variable' => $variableName]);
        
        // 匹配变量定义的各种模式
        $patterns = [
            '/var\s+'.preg_quote($variableName, '/').'\s*=\s*[\'"]([^\'"]+)[\'"]/',
            '/let\s+'.preg_quote($variableName, '/').'\s*=\s*[\'"]([^\'"]+)[\'"]/',
            '/const\s+'.preg_quote($variableName, '/').'\s*=\s*[\'"]([^\'"]+)[\'"]/',
            '/'.preg_quote($variableName, '/').'\s*=\s*[\'"]([^\'"]+)[\'"]/',
            '/var\s+'.preg_quote($variableName, '/').'\s*=\s*"([^"]+)"/',
            '/var\s+'.preg_quote($variableName, '/').'\s*=\s*\'([^\']+)\'/'
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $matches)) {
                $value = $matches[1];
                $this->log("找到变量值", ['variable' => $variableName, 'value' => $value]);
                return $value;
            }
        }
        
        $this->log("未找到变量值", ['variable' => $variableName]);
        return $variableName;
    }
    
    /**
     * 核心优化：从function more()的data对象中提取参数并解析变量值
     */
    private function extractFromMoreFunction($html) {
        $this->log("开始从function more()中提取参数");
        $parameters = [];
        
        $moreFunctionPatterns = [
            '/function\s+more\s*\(\s*\)\s*\{[^}]*\.ajax\s*\([^}]*data\s*:\s*\{([^}]+)\}[^}]*\}/s',
            '/function more\(\)\{[^}]*\.ajax\([^}]*data:\{([^}]+)\}[^}]*\}/s',
            '/function\s+more\s*\(\s*\)\s*\{[\s\S]*?\.ajax\s*\([\s\S]*?data\s*:\s*\{([^}]+)\}[\s\S]*?\}/s'
        ];
        
        foreach ($moreFunctionPatterns as $patternIndex => $pattern) {
            if (preg_match($pattern, $html, $matches)) {
                $this->log("找到function more()匹配", ['pattern_index' => $patternIndex]);
                $dataContent = $matches[1];
                
                $keyValuePatterns = [
                    '/[\'"]?(\w+)[\'"]?\s*:\s*([^,}\s]+)/',
                    '/[\'"]?(\w+)[\'"]?\s*:\s*[\'"]([^\'"]+)[\'"]/',
                    '/[\'"]?(\w+)[\'"]?\s*:\s*([^,}]+)/'
                ];
                
                foreach ($keyValuePatterns as $kvPattern) {
                    if (preg_match_all($kvPattern, $dataContent, $kvMatches, PREG_SET_ORDER)) {
                        foreach ($kvMatches as $match) {
                            $key = $match[1];
                            $value = trim($match[2], "'\" \t\n\r\0\x0B");
                            
                            if (in_array($key, ['lx', 'fid', 'uid', 'pg', 'rep', 't', 'k', 'up', 'vip', 'webfoldersign', 'puid', 'ls', 'webtype'])) {
                                if (!isset($parameters[$key]) || empty($parameters[$key])) {
                                    if (($key === 't' || $key === 'k') && preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $value)) {
                                        $resolvedValue = $this->resolveVariableValue($html, $value);
                                        $parameters[$key] = $resolvedValue;
                                        $this->log("解析变量参数", ['key' => $key, 'variable' => $value, 'value' => $resolvedValue]);
                                    } else {
                                        $parameters[$key] = $value;
                                        $this->log("从function more()提取", ['key' => $key, 'value' => $value]);
                                    }
                                }
                            }
                        }
                    }
                }
                
                if (!empty($parameters)) {
                    $this->log("function more()提取成功", $parameters);
                    return $parameters;
                }
            }
        }
        
        $ajaxPatterns = [
            '/\.ajax\s*\([^}]*data\s*:\s*\{([^}]+)\}[^}]*\)/s',
            '/\.post\s*\([^}]*data\s*:\s*\{([^}]+)\}[^}]*\)/s'
        ];
        
        foreach ($ajaxPatterns as $pattern) {
            if (preg_match_all($pattern, $html, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $dataContent = $match[1];
                    if (preg_match_all('/[\'"]?(\w+)[\'"]?\s*:\s*[\'"]?([^\'",}]+)[\'"]?/', $dataContent, $kvMatches, PREG_SET_ORDER)) {
                        foreach ($kvMatches as $kvMatch) {
                            $key = $kvMatch[1];
                            $value = trim($kvMatch[2], "'\"");
                            
                            if (in_array($key, ['lx', 'fid', 'uid', 'pg', 'rep', 't', 'k', 'up', 'vip', 'webfoldersign', 'puid', 'ls', 'webtype']) && 
                                !empty($value) && $value !== 'pgs' && $value !== 'iaydx7' && $value !== '_gwpz0') {
                                if (!isset($parameters[$key]) || empty($parameters[$key])) {
                                    if (($key === 't' || $key === 'k') && preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $value)) {
                                        $resolvedValue = $this->resolveVariableValue($html, $value);
                                        $parameters[$key] = $resolvedValue;
                                        $this->log("从ajax调用解析变量", ['key' => $key, 'variable' => $value, 'value' => $resolvedValue]);
                                    } else {
                                        $parameters[$key] = $value;
                                        $this->log("从ajax调用提取", ['key' => $key, 'value' => $value]);
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        
        $this->log("function more()提取结果", $parameters);
        return $parameters;
    }
    
    /**
     * 备用参数提取方法
     */
    private function extractFallbackParameters($html) {
        $this->log("使用备用参数提取方法");
        $parameters = [];
        
        $jsPatterns = [
            '/var\s+(\w+)\s*=\s*[\'"]([^\'"]+)[\'"]/',
            '/let\s+(\w+)\s*=\s*[\'"]([^\'"]+)[\'"]/',
            '/const\s+(\w+)\s*=\s*[\'"]([^\'"]+)[\'"]/',
            '/(\w+)\s*=\s*[\'"]([^\'"]+)[\'"]/'
        ];
        
        foreach ($jsPatterns as $pattern) {
            if (preg_match_all($pattern, $html, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $key = $match[1];
                    $value = $match[2];
                    if (in_array($key, ['lx', 'fid', 'uid', 'pg', 'rep', 't', 'k', 'up', 'vip', 'webfoldersign', 'puid', 'ls', 'webtype'])) {
                        $parameters[$key] = $value;
                        $this->log("从JS变量提取", ['key' => $key, 'value' => $value]);
                    }
                }
            }
        }
        
        return $parameters;
    }
    
    /**
     * 智能参数提取
     */
    private function extractAllParameters($html, $url) {
        $this->log("开始智能参数提取", ['url' => $url]);
        
        $parameters = $this->extractFromMoreFunction($html);
        
        $requiredParams = ['fid', 'uid', 't', 'k'];
        $missingParams = [];
        foreach ($requiredParams as $param) {
            if (empty($parameters[$param])) {
                $missingParams[] = $param;
            }
        }
        
        if (!empty($missingParams)) {
            $this->log("function more()提取不完整，使用备用方法", ['missing' => $missingParams]);
            $fallbackParams = $this->extractFallbackParameters($html);
            
            foreach ($fallbackParams as $key => $value) {
                if (empty($parameters[$key])) {
                    $parameters[$key] = $value;
                }
            }
        }
        
        foreach (['t', 'k'] as $key) {
            if (isset($parameters[$key]) && preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $parameters[$key])) {
                $resolvedValue = $this->resolveVariableValue($html, $parameters[$key]);
                if ($resolvedValue !== $parameters[$key]) {
                    $this->log("最终解析变量", ['key' => $key, 'variable' => $parameters[$key], 'value' => $resolvedValue]);
                    $parameters[$key] = $resolvedValue;
                }
            }
        }
        
        $defaults = [
            'lx' => '2',
            'rep' => '0',
            'up' => '0',
            'vip' => '0',
            'ls' => '0',
            'webtype' => '0',
            'webfoldersign' => ''
        ];
        
        foreach ($defaults as $key => $default) {
            if (empty($parameters[$key])) {
                $parameters[$key] = $default;
                $this->log("设置默认值", ['key' => $key, 'value' => $default]);
            }
        }
        
        $this->log("最终参数提取结果", $parameters);
        
        // 保存参数到文件
        $this->saveParametersToFile($url, $parameters);
        
        return $parameters;
    }
    
    private function extractDomain($url) {
        $parsedUrl = parse_url($url);
        $host = $parsedUrl['host'] ?? '';
        
        if ($host === 'app.lanzouw.com') {
            if (preg_match('/\/([a-zA-Z0-9]+)\.lanzouw\.com\//', $url, $matches)) {
                $host = $matches[1] . '.lanzouw.com';
            }
        }
        
        $this->log("域名提取", ['original_url' => $url, 'extracted_domain' => $host]);
        return $host;
    }
    
    private function normalizeUrl($url) {
        $domains = ['lanzoup.com', 'lanzoux.com', 'lanzous.com', 'lanzoui.com', 'lanzouw.com'];
        foreach ($domains as $domain) {
            $url = str_replace($domain, 'lanzoui.com', $url);
        }
        return $url;
    }
    
    private function extractFidFromUrl($url) {
        if (preg_match('/([a-zA-Z0-9]{6,12})(?:[\/\?]|$)/', $url, $matches)) {
            return $matches[1];
        }
        return null;
    }
    
    /**
     * 字符串替换辅助函数
     */
    private function stringReplace($str, $search, $replace) {
        return str_replace($search, $replace, $str);
    }
    
    /**
     * 字符串提取辅助函数
     */
    private function stringExtract($str, $start, $end) {
        $start_pos = strpos($str, $start);
        if ($start_pos === false) return '';
        $start_pos += strlen($start);
        $end_pos = strpos($str, $end, $start_pos);
        if ($end_pos === false) return '';
        return substr($str, $start_pos, $end_pos - $start_pos);
    }
    
    /**
     * 改进的文件列表处理方法 - 与之前分析保持一致
     */
    private function processFileList($jsonData, $domain) {
        $this->log("开始处理文件列表", ['data_structure' => array_keys($jsonData)]);
        $fileList = [];
        
        // 根据您提供的实际数据结构进行解析
        if (!isset($jsonData['text']) || !is_array($jsonData['text'])) {
            // 尝试不同的数据结构
            if (isset($jsonData['list']) && isset($jsonData['list']['text'])) {
                // 旧的数据结构
                $listText = $jsonData['list']['text'];
                if (is_string($listText)) {
                    // 需要解析字符串格式的列表
                    $cleanedText = $this->stringExtract($listText, "[", "]");
                    $items = explode("},{", trim($cleanedText, "{}"));
                } else {
                    $items = $listText;
                }
            } else {
                $this->log("响应中缺少text数据", ['response_keys' => array_keys($jsonData)]);
                return $fileList;
            }
        } else {
            // 新的数据结构 - 直接使用text数组
            $items = $jsonData['text'];
        }
        
        $this->log("找到文件项", ['count' => count($items), 'type' => gettype($items)]);
        
        // 处理文件项 - 与之前分析保持一致
        foreach ($items as $index => $item) {
            if (is_string($item)) {
                // 如果是字符串格式，需要解析
                $item = trim($item);
                if (strpos($item, '{') !== 0) {
                    $item = '{' . $item;
                }
                if (strpos($item, '}') !== strlen($item) - 1) {
                    $item = $item . '}';
                }
                
                $itemData = json_decode($item, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $this->log("JSON解析失败，跳过该项", ['item' => substr($item, 0, 100)]);
                    continue;
                }
            } else {
                // 已经是数组格式
                $itemData = $item;
            }
            
            // 根据实际数据结构提取字段 - 与之前分析保持一致
            $id = isset($itemData['id']) ? $itemData['id'] : (isset($itemData['duan']) ? $itemData['duan'] : '');
            $name = isset($itemData['name_all']) ? $itemData['name_all'] : '';
            $size = isset($itemData['size']) ? $itemData['size'] : '';
            $time = isset($itemData['time']) ? $itemData['time'] : '';
            $p_ico = isset($itemData['p_ico']) ? $itemData['p_ico'] : '0';
            $ico = isset($itemData['ico']) ? $itemData['ico'] : '';
            
            // 跳过无效项
            if (empty($id) || empty($name)) {
                $this->log("跳过无效文件项", ['id' => $id, 'name' => $name]);
                continue;
            }
            
            // 处理文件名 - 移除.apk后缀
            $name = $this->stringReplace($name, ".apk", "");
            
            // 处理图标URL - 与之前分析保持一致
            if ($p_ico == "0") {
                $icon = "@icon.png";
            } else {
                $icon = "https://" . $this->image_domain . ".com/image/ico/" . $ico;
            }
            
            // 构建文件URL
            $fileUrl = "https://" . $domain . "/" . $id;
            
            $fileList[] = [
                'index' => $index + 1,
                'icon' => $icon,
                'name' => $name,
                'url' => $fileUrl,
                'time' => $time,
                'size' => $size,
                'id' => $id,
                'p_ico' => $p_ico,
                'ico' => $ico
            ];
            
            $this->log("处理文件项完成", [
                'index' => $index + 1,
                'name' => $name,
                'id' => $id
            ]);
        }
        
        $this->log("文件列表处理完成", ['total_files' => count($fileList)]);
        return $fileList;
    }
    
    /**
     * 数据获取功能 - 提取参数并构建接口URL
     */
    public function getDataInterface($url) {
        try {
            $this->log("开始数据获取接口构建", ['url' => $url]);
            
            $originalUrl = $url;
            $normalizedUrl = $this->normalizeUrl($url);
            $this->log("URL处理", ['original' => $originalUrl, 'normalized' => $normalizedUrl]);
            
            $html = $this->httpRequest($normalizedUrl);
            if (is_array($html) && isset($html['error'])) {
                throw new Exception($html['error']);
            }
            
            if (strpos($html, '文件取消') !== false || strpos($html, '不存在') !== false) {
                throw new Exception('文件不存在或已取消分享');
            }
            
            $parameters = $this->extractAllParameters($html, $normalizedUrl);
            
            
            if (empty($parameters['fid'])) {
                $parameters['fid'] = $this->extractFidFromUrl($normalizedUrl);
            }
            
            $requiredParams = ['fid', 'uid', 't', 'k'];
            $missingParams = [];
            foreach ($requiredParams as $param) {
                if (empty($parameters[$param])) {
                    $missingParams[] = $param;
                }
            }
            
            if (!empty($missingParams)) {
                throw new Exception('缺少必要参数: ' . implode(', ', $missingParams));
            }
            
            $domain = $this->extractDomain($originalUrl);
            if (empty($domain)) {
                throw new Exception('无法从URL中提取域名');
            }
            
            $interfaceUrl = "https://{$domain}/filemoreajax.php?file=" . $parameters['fid'];
            
            $postData = http_build_query([
                'lx' => $parameters['lx'],
                'fid' => $parameters['fid'],
                'uid' => $parameters['uid'],
                'puid' => $parameters['puid'] ?? '',
                'pg' => '1', // 数据接口固定使用第1页参数
                'rep' => $parameters['rep'],
                't' => $parameters['t'],
                'k' => $parameters['k'],
                'up' => $parameters['up'],
                'vip' => $parameters['vip'],
                'webfoldersign' => $parameters['webfoldersign']
            ]);
            
            $this->log("POST数据构建", ['post_data' => $postData]);
            
            $result = [
                'success' => true,
                'interface_url' => $interfaceUrl,
                'post_data' => $postData,
                'parameters' => $parameters,
                'extracted_domain' => $domain
            ];
            
            $testResult = $this->testDataInterface($interfaceUrl, $postData, $normalizedUrl);
            $result['test_result'] = $testResult;
            
            $this->log("数据接口构建成功", $result);
            return $result;
            
        } catch (Exception $e) {
            $error = [
                'success' => false,
                'error' => $e->getMessage()
            ];
            
            $this->log("数据接口构建失败", $error);
            return $error;
        }
    }
    
    /**
     * 测试数据接口
     */
    private function testDataInterface($interfaceUrl, $postData, $referer) {
        $this->log("测试数据接口", ['url' => $interfaceUrl, 'post_data_length' => strlen($postData)]);
        
        $headers = [
            'Content-Type: application/x-www-form-urlencoded',
            'X-Requested-With: XMLHttpRequest',
            'Referer: ' . $referer
        ];
        
        $response = $this->httpRequest($interfaceUrl, $postData, $headers);
        
        if (is_array($response) && isset($response['error'])) {
            return [
                'success' => false,
                'error' => $response['error']
            ];
        }
        
        $jsonData = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'success' => false,
                'error' => 'JSON解析失败: ' . json_last_error_msg(),
                'raw_response' => substr($response, 0, 500)
            ];
        }
        
        return [
            'success' => true,
            'response' => $jsonData,
            'response_length' => strlen($response),
            'pg_parameter_included' => strpos($postData, 'pg=') !== false
        ];
    }
    
    /**
     * 获取文件列表 - 主要修改点：pg参数从page参数获取，其他参数从第一页获取或从文件加载
     */
    public function getFileList($url, $page = 1) {
        try {
            $this->log("开始处理文件列表请求", ['url' => $url, 'page' => $page]);
            
            $originalUrl = $url;
            $normalizedUrl = $this->normalizeUrl($url);
            $this->log("URL标准化", ['original' => $originalUrl, 'normalized' => $normalizedUrl]);
            
            // 强制使用传入的page参数作为pg值
            $page = max(1, intval($page));
            $pg = strval($page);
            $this->log("使用GET参数page作为pg值", ['page' => $page, 'pg' => $pg]);
            
            // 检查是否已经有参数文件
            $hasParamsFile = $this->hasParametersFile($normalizedUrl);
            
            // 如果是第一页，直接获取参数并保存到文件
            if ($page == 1 || !$hasParamsFile) {
                $this->log("获取或刷新第一页参数", ['page' => $page, 'has_params_file' => $hasParamsFile]);
                
                $html = $this->httpRequest($normalizedUrl);
                if (is_array($html) && isset($html['error'])) {
                    throw new Exception($html['error']);
                }
                
                if (strpos($html, '文件取消') !== false || strpos($html, '不存在') !== false) {
                    throw new Exception('文件不存在或已取消分享');
                }
                
                if (strpos($html, 'filemoreajax.php') === false || !preg_match('/data\s*:\s*\{/i', $html)) {
                    $mobileHeaders = [
                        'User-Agent: Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1',
                        'Accept-Language: zh-CN,zh;q=0.9,en;q=0.8',
                        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'
                    ];
                    $mobileHtml = $this->httpRequest($normalizedUrl, null, $mobileHeaders);
                    if (is_string($mobileHtml) && strpos($mobileHtml, 'filemoreajax.php') !== false && preg_match('/data\s*:\s*\{/i', $mobileHtml)) {
                        $html = $mobileHtml;
                    } else {
                    $staleCache = __DIR__ . '/cache/software_files/' . hash('sha256', $originalUrl . '|' . $page) . '.json';
                    if (is_file($staleCache)) {
                        $cachedBody = file_get_contents($staleCache);
                        $cachedData = json_decode($cachedBody, true);
                        if (is_array($cachedData) && !empty($cachedData['success']) && isset($cachedData['files']) && is_array($cachedData['files'])) {
                            $cachedData['cache_fallback'] = true;
                            $cachedData['fallback_reason'] = '????????????????????????';
                            return $cachedData;
                        }
                    }
                    return [
                        'success' => false,
                        'file_count' => 0,
                        'current_page' => $page,
                        'files' => [],
                        'raw_data' => [
                            'zt' => '4',
                            'info' => '??????????????????'
                        ],
                        'error' => '??????????????????',
                        'link_unavailable' => true
                    ];
                    }
                }
            
                $parameters = $this->extractAllParameters($html, $normalizedUrl);
                $parameters_source = 'extracted_from_page_and_saved_to_file';
            } else {
                $this->log("???????", ['page' => $page]);
                $parameters = $this->loadParametersFromFile($normalizedUrl);
                
                if (!$parameters) {
                    throw new Exception('???????????????????????????');
                }
                $parameters_source = 'loaded_from_file';
            }
            
            if (empty($parameters['fid'])) {
                $parameters['fid'] = $this->extractFidFromUrl($normalizedUrl);
            }
            
            // 使用传入的page参数作为pg值
            $parameters['pg'] = $pg;
            
            $requiredParams = ['fid', 'uid', 't', 'k'];
            $missingParams = [];
            foreach ($requiredParams as $param) {
                if (empty($parameters[$param])) {
                    $missingParams[] = $param;
                }
            }
            
            if (!empty($missingParams)) {
                $this->log("缺少必要参数", [
                    'missing' => $missingParams,
                    'all_parameters' => $parameters
                ]);
                throw new Exception('缺少必要参数: ' . implode(', ', $missingParams));
            }
            
            $domain = parse_url($normalizedUrl, PHP_URL_HOST);
            $ajaxUrl = "https://{$domain}/filemoreajax.php?file=" . urlencode((string) $parameters['fid']);
            
            $postData = http_build_query([
                'lx' => $parameters['lx'],
                'fid' => $parameters['fid'],
                'uid' => $parameters['uid'],
                'puid' => $parameters['puid'] ?? '',
                'pg' => $parameters['pg'], // 使用从page参数得到的值
                'rep' => $parameters['rep'],
                't' => $parameters['t'],
                'k' => $parameters['k'],
                'up' => $parameters['up'],
                'ls' => $parameters['ls'],
                'webtype' => $parameters['webtype']
            ]);
            
            $this->log("发送AJAX请求", [
                'url' => $ajaxUrl, 
                'data' => $postData,
                'pg_value' => $parameters['pg'],
                'parameters_source' => $parameters_source
            ]);
            
            $headers = [
                'Content-Type: application/x-www-form-urlencoded',
                'X-Requested-With: XMLHttpRequest',
                'Referer: ' . $originalUrl
            ];
            
            $ajaxResponse = $this->httpRequest($ajaxUrl, $postData, $headers);
            if (is_array($ajaxResponse) && isset($ajaxResponse['error'])) {
                throw new Exception('AJAX请求失败: ' . $ajaxResponse['error']);
            }
            
            $jsonData = json_decode($ajaxResponse, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('JSON解析失败: ' . json_last_error_msg());
            }
            
            $fileList = $this->processFileList($jsonData, $domain);
            
            $result = [
                'success' => true,
                'file_count' => count($fileList),
                'current_page' => intval($parameters['pg']),
                'files' => $fileList,
                'raw_data' => $jsonData,
                'post_parameters' => [
                    'pg' => $parameters['pg'],
                    'fid' => $parameters['fid'],
                    'uid' => $parameters['uid'],
                    't' => $parameters['t'],
                    'k' => substr($parameters['k'], 0, 10) . '...'
                ],
                'parameters_source' => $parameters_source
            ];
            
            if ($this->debug_mode) {
                $result['debug'] = [
                    'parameters' => $parameters,
                    'extraction_method' => 'function_more_optimized',
                    'raw_data_keys' => array_keys($jsonData),
                    'post_data_verified' => strpos($postData, 'pg=' . $parameters['pg']) !== false,
                    'has_params_file' => $this->hasParametersFile($normalizedUrl),
                    'params_file' => $this->getFilenameFromUrl($normalizedUrl)
                ];
            }
            
            $this->log("文件列表获取成功", [
                'file_count' => count($fileList),
                'pg_used' => $parameters['pg'],
                'parameters_source' => $parameters_source,
                'post_data_verified' => strpos($postData, 'pg=' . $parameters['pg']) !== false
            ]);
            return $result;
            
        } catch (Exception $e) {
            $error = [
                'success' => false,
                'error' => $e->getMessage()
            ];
            
            $this->log("处理失败", $error);
            return $error;
        }
    }
    
    /**
     * 获取原始数据列表功能
     */
    public function getRawDataList($url) {
        try {
            $this->log("开始获取原始数据列表", ['url' => $url]);
            
            // 总是从第一页获取原始数据
            $fileListResult = $this->getFileList($url, 1);
            
            if (!$fileListResult['success']) {
                throw new Exception($fileListResult['error']);
            }
            
            $rawData = $fileListResult['raw_data'] ?? [];
            $textList = $rawData['text'] ?? [];
            
            $processedList = [];
            foreach ($textList as $index => $item) {
                if (is_array($item)) {
                    $itemData = $item;
                } else {
                    $itemData = json_decode($item, true) ?: ['raw_text' => $item];
                }
                
                $processedList[] = [
                    'index' => $index + 1,
                    'data' => $itemData,
                    'data_type' => gettype($itemData),
                    'data_size' => is_array($itemData) ? count($itemData) : strlen($itemData)
                ];
            }
            
            $result = [
                'success' => true,
                'total_count' => count($processedList),
                'raw_list' => $processedList,
                'raw_data_info' => [
                    'keys' => array_keys($rawData),
                    'data_types' => array_map('gettype', $rawData),
                    'total_size' => strlen(json_encode($rawData)),
                    'pg_parameter' => $fileListResult['post_parameters']['pg'] ?? '1',
                    'source_page' => '1' // 原始数据总是从第一页获取
                ]
            ];
            
            $this->log("原始数据列表获取成功", [
                'total_count' => count($processedList),
                'pg_parameter' => $fileListResult['post_parameters']['pg'] ?? '1'
            ]);
            
            return $result;
            
        } catch (Exception $e) {
            $error = [
                'success' => false,
                'error' => $e->getMessage()
            ];
            
            $this->log("原始数据列表获取失败", $error);
            return $error;
        }
    }
    
    public function debugExtraction($url) {
        try {
            $normalizedUrl = $this->normalizeUrl($url);
            $html = $this->httpRequest($normalizedUrl);
            
            if (is_array($html) && isset($html['error'])) {
                throw new Exception($html['error']);
            }
            
            $moreParams = $this->extractFromMoreFunction($html);
            $fallbackParams = $this->extractFallbackParameters($html);
            $finalParams = $this->extractAllParameters($html, $normalizedUrl);
            
            $variableTests = [];
            foreach (['t', 'k'] as $key) {
                if (isset($finalParams[$key]) && preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $finalParams[$key])) {
                    $variableTests[$key] = [
                        'variable' => $finalParams[$key],
                        'resolved' => $this->resolveVariableValue($html, $finalParams[$key])
                    ];
                }
            }
            
            $dataInterface = $this->getDataInterface($url);
            
            // 测试第一页
            $page1Result = $this->getFileList($url, 1);
            
            // 测试第二页（如果第一页成功）
            $page2Result = null;
            if ($page1Result['success']) {
                $page2Result = $this->getFileList($url, 2);
            }
            
            // 检查参数文件
            $hasParamsFile = $this->hasParametersFile($normalizedUrl);
            $paramsFilename = $this->getFilenameFromUrl($normalizedUrl);
            
            return [
                'success' => true,
                'url' => $url,
                'html_length' => strlen($html),
                'more_function_params' => $moreParams,
                'fallback_params' => $fallbackParams,
                'final_params' => $finalParams,
                'variable_tests' => $variableTests,
                'data_interface' => $dataInterface,
                'page_1_result' => $page1Result['success'] ? '成功' : '失败',
                'page_2_result' => $page2Result ? ($page2Result['success'] ? '成功' : '失败') : '未测试',
                'params_file_info' => [
                    'has_file' => $hasParamsFile,
                    'filename' => $paramsFilename,
                    'full_path' => $hasParamsFile ? $this->params_dir . $paramsFilename : '无'
                ],
                'has_more_function' => strpos($html, 'function more') !== false,
                'pg_extracted' => $moreParams['pg'] ?? $fallbackParams['pg'] ?? '未提取到'
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}

// 设置响应头
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$debug = isset($_GET['debug']) ? filter_var($_GET['debug'], FILTER_VALIDATE_BOOLEAN) : true;
$parser = new LanzouParserOptimized($debug);

$url = isset($_GET['url']) ? trim($_GET['url']) : '';
$action = isset($_GET['action']) ? $_GET['action'] : 'filelist';
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;

if (empty($url)) {
    echo json_encode([
        'success' => false,
        'error' => '请提供URL参数'
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

if ($action === 'debug') {
    $result = $parser->debugExtraction($url);
} elseif ($action === 'data_interface') {
    $result = $parser->getDataInterface($url);
} elseif ($action === 'raw_data') {
    $result = $parser->getRawDataList($url);
} else {
    $result = $parser->getFileList($url, $page);
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

?>
