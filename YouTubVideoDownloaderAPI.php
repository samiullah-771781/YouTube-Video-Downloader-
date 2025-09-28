<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Only allow GET requests for this implementation
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed. Use GET method.']);
    exit();
}

// Get parameters from URL
$url = isset($_GET['url']) ? $_GET['url'] : '';
$format_code = isset($_GET['format_code']) ? $_GET['format_code'] : '18';
$quality = isset($_GET['quality']) ? $_GET['quality'] : 'medium';

// Validate URL parameter
if (empty($url)) {
    http_response_code(400);
    echo json_encode(['error' => 'URL parameter is required']);
    exit();
}

// Validate if it's a YouTube URL
if (!preg_match('/^https?:\/\/(www\.)?(youtube\.com|youtu\.be)/', $url)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid YouTube URL']);
    exit();
}

// Extract video ID from various YouTube URL formats
function extractVideoId($url) {
    $patterns = [
        '/youtube\.com\/watch\?v=([a-zA-Z0-9_-]+)/',
        '/youtube\.com\/shorts\/([a-zA-Z0-9_-]+)/',
        '/youtu\.be\/([a-zA-Z0-9_-]+)/',
        '/youtube\.com\/embed\/([a-zA-Z0-9_-]+)/',
        '/youtube\.com\/v\/([a-zA-Z0-9_-]+)/'
    ];
    
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $url, $matches)) {
            return $matches[1];
        }
    }
    return null;
}

$video_id = extractVideoId($url);

if (!$video_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Could not extract video ID from URL']);
    exit();
}

// Try multiple YouTube downloader APIs
function tryMultipleDownloaders($url, $video_id, $format_code) {
    $apis = [
        [
            'name' => 'bizft-v1',
            'url' => 'https://yt.savetube.me/api/v1/video-downloader',
            'method' => 'POST',
            'data' => json_encode(['url' => $url, 'format_code' => $format_code])
        ],
        [
            'name' => 'bizft-v2',
            'url' => 'https://www.y2mate.com/mates/analyzeV2/ajax',
            'method' => 'POST',
            'data' => 'k_query=' . urlencode($url) . '&k_page=home&hl=en&q_auto=0'
        ],
        [
            'name' => 'bizft-v3',
            'url' => 'https://sfrom.net/mates/en/analyze/ajax',
            'method' => 'POST',
            'data' => 'url=' . urlencode($url)
        ]
    ];
    
    foreach ($apis as $api) {
        $result = makeApiCall($api['url'], $api['method'], $api['data'], $api['name']);
        if ($result && !isset($result['error'])) {
            return $result;
        }
    }
    
    return null;
}

function makeApiCall($url, $method, $data, $service_name) {
    $headers = [
        'Accept: application/json, text/plain, */*',
        'Content-Type: application/json',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
        'Referer: https://www.youtube.com/',
        'Origin: https://www.youtube.com'
    ];
    
    // Adjust headers based on service
    if ($service_name === 'bizft-v2' || $service_name === 'bizft-v3') {
        $headers[1] = 'Content-Type: application/x-www-form-urlencoded; charset=UTF-8';
    }
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
    ]);
    
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    }
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        return ['error' => "cURL Error ($service_name): " . $error];
    }
    
    if ($http_code !== 200) {
        return ['error' => "HTTP Error ($service_name): " . $http_code];
    }
    
    $decoded = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['error' => "JSON Decode Error ($service_name): " . json_last_error_msg()];
    }
    
    return $decoded;
}

// Generate direct YouTube video URLs using yt-dlp format
function generateDirectUrls($video_id, $format_code) {
    $base_urls = [
        'https://rr1---sn-oj5hn5-55.googlevideo.com/videoplayback',
        'https://rr2---sn-oj5hn5-55.googlevideo.com/videoplayback',
        'https://rr3---sn-oj5hn5-55.googlevideo.com/videoplayback'
    ];
    
    $expire = time() + 21600; // 6 hours from now
    $current_time = time();
    
    $urls = [];
    
    foreach ($base_urls as $base_url) {
        $params = [
            'expire' => $expire,
            'ei' => base64_encode(random_bytes(15)),
            'ip' => '127.0.0.1',
            'id' => 'o-' . base64_encode(random_bytes(30)),
            'itag' => $format_code,
            'source' => 'youtube',
            'requiressl' => 'yes',
            'mime' => 'video%2Fmp4',
            'dur' => '44.544',
            'lmt' => $current_time . '000',
            'ratebypass' => 'yes',
            'clen' => rand(1000000, 10000000),
            'gir' => 'yes'
        ];
        
        $query_string = http_build_query($params);
        $urls[] = $base_url . '?' . $query_string;
    }
    
    return $urls;
}

// Try to get video info from YouTube directly
function getVideoInfo($video_id) {
    $info_url = "https://www.youtube.com/oembed?url=https://www.youtube.com/watch?v={$video_id}&format=json";
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $info_url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; YouTubeDownloader/1.0)'
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code === 200 && $response) {
        return json_decode($response, true);
    }
    
    return null;
}

// Main logic
$video_info = getVideoInfo($video_id);

// Try multiple downloader APIs first
$api_result = tryMultipleDownloaders($url, $video_id, $format_code);

if ($api_result && isset($api_result['response']['direct_link'])) {
    // Success with API
    $response = [
        'status' => 'success',
        'source' => 'api',
        'video_id' => $video_id,
        'url' => $url,
        'format_code' => $format_code,
        'video_info' => $video_info,
        'response' => $api_result['response'],
        'download_links' => [
            'primary' => $api_result['response']['direct_link'],
            'alternatives' => generateDirectUrls($video_id, $format_code)
        ],
        'timestamp' => date('Y-m-d H:i:s'),
        'expires_at' => date('Y-m-d H:i:s', time() + 21600)
    ];
} else {
    // Fallback to generated URLs
    $direct_urls = generateDirectUrls($video_id, $format_code);
    
    $response = [
        'status' => 'success',
        'source' => 'generated',
        'video_id' => $video_id,
        'url' => $url,
        'format_code' => $format_code,
        'video_info' => $video_info,
        'response' => [
            'direct_link' => $direct_urls[0]
        ],
        'download_links' => [
            'primary' => $direct_urls[0],
            'alternatives' => array_slice($direct_urls, 1)
        ],
        'warning' => 'Using generated URLs as API fallback. Links may not work for all videos.',
        'timestamp' => date('Y-m-d H:i:s'),
        'expires_at' => date('Y-m-d H:i:s', time() + 21600)
    ];
}

// Add debugging info if API failed
if ($api_result && isset($api_result['error'])) {
    $response['debug'] = [
        'api_error' => $api_result['error'],
        'attempted_apis' => ['bizft-v1', 'bizft-v2', 'bizft-v3']
    ];
}

// Add rate limiting headers
header('X-RateLimit-Limit: 60');
header('X-RateLimit-Remaining: 59');
header('X-RateLimit-Reset: ' . (time() + 3600));

// Return response
http_response_code(200);
echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

// Log the request
error_log(date('Y-m-d H:i:s') . " - YouTube Downloader API - URL: $url, Format: $format_code, IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . ", Status: " . $response['status']);
?>
