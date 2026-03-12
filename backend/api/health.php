<?php
// Health check endpoint

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(false, null, 'Method not allowed', 405);
}

// Check database connection
try {
    $db = getDB();
    $dbStatus = 'connected';
} catch (Exception $e) {
    $dbStatus = 'disconnected';
}

jsonResponse(true, [
    'status' => 'healthy',
    'version' => '3.0.0-php',
    'environment' => 'production',
    'database' => $dbStatus,
    'timestamp' => date('c')
]);
?>
