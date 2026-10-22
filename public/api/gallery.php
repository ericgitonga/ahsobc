<?php
/**
 * Headless Omeka S Search, Album & Metadata Update JSON API (/api/gallery.php).
 *
 * - GET  /api/gallery.php : Queries items, albums, and facets.
 * - POST /api/gallery.php : Updates per-image metadata (action=update_metadata).
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/crowdsourcing_guard.php';
require_once dirname(__DIR__) . '/includes/headless_gallery.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$conn = ahsobc_open_archive_db(
    getenv('AHSOBC_ARCHIVE_DB') ?: null,
    dirname(__DIR__, 2) . '/omeka/config/database.ini'
);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
    $rlFile = dirname(__DIR__, 2) . '/.omeka-runtime/collecting_ratelimit.json';
    $maxReq = (int) (getenv('AHSOBC_RATE_LIMIT_MAX') ?: 25);

    if (!ahsobc_check_rate_limit($clientIp, $rlFile, $maxReq, 3600)) {
        http_response_code(429);
        echo json_encode([
            'status' => 'error',
            'errors' => ['Rate limit exceeded. Please try again later.'],
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
        exit;
    }

    $rawBody = (string) file_get_contents('php://input');
    $jsonInput = $rawBody !== '' ? json_decode($rawBody, true) : null;
    $input = is_array($jsonInput) ? $jsonInput : $_POST;

    $submittedCsrf = (string) ($input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    if (!ahsobc_verify_csrf_token($submittedCsrf)) {
        http_response_code(403);
        echo json_encode([
            'status' => 'error',
            'errors' => ['Invalid or missing security token (CSRF). Please reload the gallery page and try again.'],
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
        exit;
    }

    $editorToken = (string) ($input['editor_token'] ?? ($_SERVER['HTTP_X_EDITOR_TOKEN'] ?? ''));
    if (!ahsobc_verify_editor_token($editorToken)) {
        http_response_code(401);
        echo json_encode([
            'status' => 'error',
            'errors' => ['Valid archivist editor token required for direct metadata updates.'],
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
        exit;
    }

    $action = (string) ($input['action'] ?? 'update_metadata');
    if ($action === 'update_metadata') {
        $res = ahsobc_update_item_metadata($conn['pdo'], $conn['driver'], $input);
        if (!$res['valid']) {
            http_response_code(422);
            echo json_encode([
                'status' => 'error',
                'errors' => $res['errors'],
            ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
            exit;
        }
        echo json_encode([
            'status' => 'ok',
            'message' => 'Metadata updated in Omeka S catalog.',
            'item' => $res['item'],
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
        exit;
    }
}

$payload = ahsobc_query_headless_gallery($conn['pdo'], $conn['driver'], $_GET);
echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
