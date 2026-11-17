<?php
/**
 * DevHorizan Database Connection (SQLite via PDO)
 * Provides thread-safe, secure singleton connection with foreign keys enabled.
 */

function getDb(): PDO {
    static $db = null;
    if ($db === null) {
        $dbPath = __DIR__ . '/../data/devhorizan.db';
        if (!file_exists($dbPath)) {
            $dataDir = dirname($dbPath);
            if (!is_dir($dataDir)) {
                mkdir($dataDir, 0755, true);
            }
        }

        $dsn = 'sqlite:' . $dbPath;
        try {
            $db = new PDO($dsn);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $db->exec('PRAGMA foreign_keys = ON;');
        } catch (PDOException $e) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => 'Database connection failed: ' . $e->getMessage()
            ]);
            exit;
        }
    }
    return $db;
}
