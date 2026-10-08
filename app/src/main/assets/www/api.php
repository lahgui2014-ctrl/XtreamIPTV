<?php
/**
 * Xtream Studio IPTV — Relais Proxy PHP pour InfinityFree / cPanel (Apache + PHP)
 * Gère les appels JSON vers player_api.php et le relais des playlists HLS (.m3u8) / flux (.ts / .mp4)
 */

error_reporting(0);
ini_set('display_errors', '0');
set_time_limit(0);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Range, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$mode = isset($_GET['mode']) ? $_GET['mode'] : 'xtream';

// MODE 1 : Relais flux vidéo ou playlist HLS (.m3u8)
if ($mode === 'stream') {
    $streamUrl = isset($_GET['url']) ? trim($_GET['url']) : '';
    if (empty($streamUrl)) {
        http_response_code(400);
        echo 'Missing url parameter';
        exit;
    }

    $isM3u8 = (stripos($streamUrl, '.m3u8') !== false);

    if ($isM3u8) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $streamUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'IPTVSmartersPlayer');
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        $playlistText = curl_exec($ch);
        $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 400 || !$playlistText) {
            http_response_code($httpCode ?: 502);
            echo 'Upstream playlist error';
            exit;
        }

        if (empty($finalUrl)) {
            $finalUrl = $streamUrl;
        }

        $parsed = parse_url($finalUrl);
        $originUrl = $parsed['scheme'] . '://' . $parsed['host'] . (isset($parsed['port']) ? ':' . $parsed['port'] : '');
        $basePath = substr($finalUrl, 0, strrpos($finalUrl, '/') + 1);

        $scriptPath = $_SERVER['SCRIPT_NAME'];
        $lines = explode("\n", $playlistText);
        $outputLines = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                $outputLines[] = $line;
                continue;
            }
            if (strpos($trimmed, '#') === 0) {
                $outputLines[] = $line;
                continue;
            }
            if (stripos($trimmed, 'http://') === 0 || stripos($trimmed, 'https://') === 0) {
                $absUrl = $trimmed;
            } elseif (strpos($trimmed, '/') === 0) {
                $absUrl = $originUrl . $trimmed;
            } else {
                $absUrl = $basePath . $trimmed;
            }

            $outputLines[] = $scriptPath . '?mode=stream&url=' . urlencode($absUrl);
        }

        header('Content-Type: application/vnd.apple.mpegurl');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        echo implode("\n", $outputLines);
        exit;
    }

    // Relais direct des segments .ts / .mp4 en streaming continu
    header('Content-Type: video/mp2t');
    header('Cache-Control: no-cache');

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $streamUrl);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'IPTVSmartersPlayer');
    curl_setopt($ch, CURLOPT_BUFFERSIZE, 65536);
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($curl, $data) {
        echo $data;
        if (ob_get_level() > 0) {
            @ob_flush();
        }
        flush();
        return strlen($data);
    });

    curl_exec($ch);
    curl_close($ch);
    exit;
}

// MODE 2 : Relais API Xtream Codes (player_api.php)
header('Content-Type: application/json; charset=utf-8');

$baseUrl = isset($_GET['baseUrl']) ? rtrim(trim($_GET['baseUrl']), '/') : '';
$username = isset($_GET['username']) ? trim($_GET['username']) : '';
$password = isset($_GET['password']) ? trim($_GET['password']) : '';

if (empty($baseUrl) || empty($username) || empty($password)) {
    http_response_code(400);
    echo json_encode(['error' => 'Paramètres Xtream manquants (baseUrl, username, password)']);
    exit;
}

$queryParams = [
    'username' => $username,
    'password' => $password,
];

if (!empty($_GET['action'])) {
    $queryParams['action'] = $_GET['action'];
}

foreach ($_GET as $k => $v) {
    if (!in_array($k, ['mode', 'baseUrl', 'username', 'password', 'action']) && $v !== '') {
        $queryParams[$k] = $v;
    }
}

$targetUrl = $baseUrl . '/player_api.php?' . http_build_query($queryParams);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $targetUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
curl_setopt($ch, CURLOPT_USERAGENT, 'IPTVSmartersPlayer');
curl_setopt($ch, CURLOPT_TIMEOUT, 25);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_error($ch);
curl_close($ch);

if ($response === false || $httpCode >= 400) {
    http_response_code($httpCode ?: 502);
    echo json_encode([
        'error' => 'Impossible de joindre le serveur Xtream (' . ($curlErr ?: 'HTTP ' . $httpCode) . ')'
    ]);
    exit;
}

echo $response;
