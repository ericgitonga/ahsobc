<?php
/**
 * Headless Omeka S Search, Album & Metadata Update JSON API (/api/gallery.php).
 *
 * - GET  /api/gallery.php : Queries items, albums, and facets.
 * - POST /api/gallery.php : Updates per-image metadata (action=update_metadata).
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/headless_gallery.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$conn = ahsobc_open_archive_db(
    getenv('AHSOBC_ARCHIVE_DB') ?: null,
    dirname(__DIR__, 2) . '/omeka/config/database.ini'
);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $rawBody = (string) file_get_contents('php://input');
    $jsonInput = $rawBody !== '' ? json_decode($rawBody, true) : null;
    $input = is_array($jsonInput) ? $jsonInput : $_POST;

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
