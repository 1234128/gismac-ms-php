<?php
// Authentication endpoints

$method = $_SERVER['REQUEST_METHOD'];
$input = getJsonInput();

switch ($method) {
    case 'POST':
        // Login
        if (empty($input['email']) || empty($input['password'])) {
            jsonResponse(false, null, 'Please provide email and password', 400);
        }
        
        $email = strtolower(sanitize($input['email']));
        $password = $input['password'];
        
        $db = getDB();
        $stmt = $db->prepare("SELECT * FROM users WHERE email = ? AND is_active = 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if (!$user || !password_verify($password, $user['password'])) {
            // Increment login attempts
            if ($user) {
                $stmt = $db->prepare("
                    UPDATE users 
                    SET login_attempts = login_attempts + 1,
                        lock_until = CASE WHEN login_attempts >= 4 THEN DATE_ADD(NOW(), INTERVAL 2 HOUR) ELSE lock_until END
                    WHERE id = ?
                ");
                $stmt->execute([$user['id']]);
            }
            
            jsonResponse(false, null, [
                'code' => 'INVALID_CREDENTIALS',
                'message' => 'Invalid email or password',
                'remainingAttempts' => 4
            ], 401);
        }
        
        // Check if account is locked
        if ($user['lock_until'] && strtotime($user['lock_until']) > time()) {
            jsonResponse(false, null, 'Account is temporarily locked', 423);
        }
        
        // Reset login attempts and update last login
        $stmt = $db->prepare("
            UPDATE users 
            SET login_attempts = 0, 
                lock_until = NULL,
                last_login = NOW() 
            WHERE id = ?
        ");
        $stmt->execute([$user['id']]);
        
        // Get privileges
        $stmt = $db->prepare("SELECT resource, action, allowed FROM user_privileges WHERE user_id = ?");
        $stmt->execute([$user['id']]);
        $privileges = $stmt->fetchAll();
        
        $privilegeMap = [
            'userManagement' => ['add' => false, 'edit' => false, 'delete' => false, 'view' => false],
            'tierManagement' => ['configure' => false, 'delete' => false, 'add' => false],
            'records' => ['delete' => false, 'edit' => false, 'view' => false, 'add' => false],
            'system' => ['settings' => false, 'backup' => false, 'restore' => false]
        ];
        
        foreach ($privileges as $priv) {
            if (isset($privilegeMap[$priv['resource']])) {
                $privilegeMap[$priv['resource']][$priv['action']] = (bool)$priv['allowed'];
            }
        }
        
        // Generate JWT
        $token = generateJWT(['id' => $user['id'], 'email' => $user['email']]);
        
        jsonResponse(true, [
            'user' => [
                'id' => $user['id'],
                'employeeId' => $user['employee_id'],
                'firstName' => $user['first_name'],
                'lastName' => $user['last_name'],
                'email' => $user['email'],
                'role' => $user['role'],
                'tier' => (int)$user['tier'],
                'department' => $user['department'],
                'privileges' => $privilegeMap
            ],
            'tokens' => [
                'accessToken' => $token,
                'accessTokenExpiry' => 86400
            ]
        ]);
        break;
        
    case 'GET':
        // Get current user (me)
        $user = requireAuth();
        
        $stmt = $db->prepare("SELECT resource, action, allowed FROM user_privileges WHERE user_id = ?");
        $stmt->execute([$user['id']]);
        $privileges = $stmt->fetchAll();
        
        $privilegeMap = [
            'userManagement' => ['add' => false, 'edit' => false, 'delete' => false, 'view' => false],
            'tierManagement' => ['configure' => false, 'delete' => false, 'add' => false],
            'records' => ['delete' => false, 'edit' => false, 'view' => false, 'add' => false],
            'system' => ['settings' => false, 'backup' => false, 'restore' => false]
        ];
        
        foreach ($privileges as $priv) {
            if (isset($privilegeMap[$priv['resource']])) {
                $privilegeMap[$priv['resource']][$priv['action']] = (bool)$priv['allowed'];
            }
        }
        
        jsonResponse(true, [
            'id' => $user['id'],
            'employeeId' => $user['employee_id'],
            'firstName' => $user['first_name'],
            'lastName' => $user['last_name'],
            'email' => $user['email'],
            'role' => $user['role'],
            'tier' => (int)$user['tier'],
            'department' => $user['department'],
            'privileges' => $privilegeMap
        ]);
        break;
        
    case 'PATCH':
        // Update password
        $user = requireAuth();
        $input = getJsonInput();
        
        if (empty($input['currentPassword']) || empty($input['newPassword'])) {
            jsonResponse(false, null, 'Please provide current and new password', 400);
        }
        
        if (!password_verify($input['currentPassword'], $user['password'])) {
            jsonResponse(false, null, 'Current password is incorrect', 401);
        }
        
        $newHash = password_hash($input['newPassword'], PASSWORD_DEFAULT);
        $stmt = $db->prepare("UPDATE users SET password = ?, password_changed_at = NOW() WHERE id = ?");
        $stmt->execute([$newHash, $user['id']]);
        
        // Generate new token
        $token = generateJWT(['id' => $user['id'], 'email' => $user['email']]);
        
        jsonResponse(true, ['token' => $token]);
        break;
        
    default:
        jsonResponse(false, null, 'Method not allowed', 405);
}
?>
