<?php
// Helper functions

function jsonResponse($success, $data = null, $error = null, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    
    $response = ['success' => $success];
    
    if ($data !== null) {
        $response['data'] = $data;
    }
    
    if ($error !== null) {
        if (is_string($error)) {
            $response['error'] = ['message' => $error];
        } else {
            $response['error'] = $error;
        }
    }
    
    // Add timestamp
    $response['timestamp'] = date('c');
    
    echo json_encode($response);
    exit;
}

function getJsonInput() {
    $json = file_get_contents('php://input');
    return json_decode($json, true) ?? [];
}

function sanitize($data) {
    return htmlspecialchars(strip_tags(trim($data)));
}

function generateEmployeeId($db) {
    $year = date('Y');
    $stmt = $db->query("SELECT COUNT(*) as count FROM users");
    $count = $stmt->fetch()['count'] + 1001;
    return "GIS-{$year}-" . str_pad($count, 4, '0', STR_PAD_LEFT);
}

function hasPrivilege($userId, $resource, $action) {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT allowed FROM user_privileges 
        WHERE user_id = ? AND resource = ? AND action = ?
    ");
    $stmt->execute([$userId, $resource, $action]);
    $result = $stmt->fetch();
    return $result && $result['allowed'];
}

function requireAuth() {
    $headers = getallheaders();
    $auth = $headers['Authorization'] ?? '';
    
    if (!preg_match('/Bearer\s(\S+)/', $auth, $matches)) {
        jsonResponse(false, null, 'No token provided', 401);
    }
    
    $token = $matches[1];
    $userId = verifyJWT($token);
    
    if (!$userId) {
        jsonResponse(false, null, 'Invalid or expired token', 401);
    }
    
    // Check if user exists and is active
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ? AND is_active = 1");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    
    if (!$user) {
        jsonResponse(false, null, 'User not found or inactive', 401);
    }
    
    return $user;
}

function requirePrivilege($resource, $action) {
    $user = requireAuth();
    
    if (!hasPrivilege($user['id'], $resource, $action)) {
        jsonResponse(false, null, "Insufficient privileges: {$resource}.{$action} required", 403);
    }
    
    return $user;
}
?>
