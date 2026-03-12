<?php
// User management endpoints

$method = $_SERVER['REQUEST_METHOD'];
$input = getJsonInput();

// Get user ID from URL if present
$userId = isset($segments[3]) ? (int)$segments[3] : null;

switch ($method) {
    case 'GET':
        if ($userId) {
            // Get single user
            requirePrivilege('userManagement', 'view');
            
            $db = getDB();
            $stmt = $db->prepare("
                SELECT u.*, 
                    GROUP_CONCAT(CONCAT(p.resource, '.', p.action, ':', p.allowed)) as privileges
                FROM users u
                LEFT JOIN user_privileges p ON u.id = p.user_id
                WHERE u.id = ?
                GROUP BY u.id
            ");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            
            if (!$user) {
                jsonResponse(false, null, 'User not found', 404);
            }
            
            // Parse privileges
            $privilegeMap = [];
            if ($user['privileges']) {
                foreach (explode(',', $user['privileges']) as $priv) {
                    list($key, $val) = explode(':', $priv);
                    list($res, $act) = explode('.', $key);
                    $privilegeMap[$res][$act] = (bool)$val;
                }
            }
            $user['privileges'] = $privilegeMap;
            unset($user['password']);
            
            jsonResponse(true, $user);
        } else {
            // List all users
            requirePrivilege('userManagement', 'view');
            
            $db = getDB();
            
            // Filter by role if provided
            $roleFilter = $_GET['role'] ?? null;
            
            $sql = "SELECT id, employee_id, first_name, last_name, email, role, tier, department, is_active, created_at 
                    FROM users WHERE 1=1";
            $params = [];
            
            if ($roleFilter) {
                $sql .= " AND role = ?";
                $params[] = $roleFilter;
            }
            
            $sql .= " ORDER BY created_at DESC";
            
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $users = $stmt->fetchAll();
            
            jsonResponse(true, ['count' => count($users), 'data' => $users]);
        }
        break;
        
    case 'POST':
        // Create user
        requirePrivilege('userManagement', 'add');
        
        // Validation
        $required = ['email', 'password', 'firstName', 'lastName', 'role'];
        foreach ($required as $field) {
            if (empty($input[$field])) {
                jsonResponse(false, null, "Field {$field} is required", 400);
            }
        }
        
        $db = getDB();
        
        // Check if email exists
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([strtolower($input['email'])]);
        if ($stmt->fetch()) {
            jsonResponse(false, null, 'Email already exists', 400);
        }
        
        // Generate employee ID
        $employeeId = generateEmployeeId($db);
        
        // Hash password
        $passwordHash = password_hash($input['password'], PASSWORD_DEFAULT);
        
        // Insert user
        $stmt = $db->prepare("
            INSERT INTO users (email, password, first_name, last_name, role, tier, department, employee_id, is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
        ");
        
        $tier = $input['tier'] ?? 5;
        $department = $input['department'] ?? 'Operations';
        
        $stmt->execute([
            strtolower($input['email']),
            $passwordHash,
            $input['firstName'],
            $input['lastName'],
            $input['role'],
            $tier,
            $department,
            $employeeId
        ]);
        
        $newUserId = $db->lastInsertId();
        
        // Insert privileges
        if (!empty($input['privileges'])) {
            $privStmt = $db->prepare("
                INSERT INTO user_privileges (user_id, resource, action, allowed) 
                VALUES (?, ?, ?, ?)
            ");
            
            foreach ($input['privileges'] as $resource => $actions) {
                foreach ($actions as $action => $allowed) {
                    $privStmt->execute([$newUserId, $resource, $action, $allowed ? 1 : 0]);
                }
            }
        }
        
        jsonResponse(true, ['id' => $newUserId, 'employeeId' => $employeeId], null, 201);
        break;
        
    case 'PATCH':
        // Update user
        requirePrivilege('userManagement', 'edit');
        
        if (!$userId) {
            jsonResponse(false, null, 'User ID required', 400);
        }
        
        $db = getDB();
        
        // Check if user exists
        $stmt = $db->prepare("SELECT id FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        if (!$stmt->fetch()) {
            jsonResponse(false, null, 'User not found', 404);
        }
        
        // Build update query
        $updates = [];
        $params = [];
        
        if (isset($input['firstName'])) {
            $updates[] = "first_name = ?";
            $params[] = $input['firstName'];
        }
        if (isset($input['lastName'])) {
            $updates[] = "last_name = ?";
            $params[] = $input['lastName'];
        }
        if (isset($input['email'])) {
            $updates[] = "email = ?";
            $params[] = strtolower($input['email']);
        }
        if (isset($input['role'])) {
            $updates[] = "role = ?";
            $params[] = $input['role'];
        }
        if (isset($input['tier'])) {
            $updates[] = "tier = ?";
            $params[] = $input['tier'];
        }
        if (isset($input['department'])) {
            $updates[] = "department = ?";
            $params[] = $input['department'];
        }
        if (isset($input['isActive'])) {
            $updates[] = "is_active = ?";
            $params[] = $input['isActive'] ? 1 : 0;
        }
        
        if (empty($updates)) {
            jsonResponse(false, null, 'No fields to update', 400);
        }
        
        $params[] = $userId;
        $sql = "UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        
        // Update privileges if provided
        if (!empty($input['privileges'])) {
            // Delete old privileges
            $stmt = $db->prepare("DELETE FROM user_privileges WHERE user_id = ?");
            $stmt->execute([$userId]);
            
            // Insert new privileges
            $privStmt = $db->prepare("
                INSERT INTO user_privileges (user_id, resource, action, allowed) 
                VALUES (?, ?, ?, ?)
            ");
            
            foreach ($input['privileges'] as $resource => $actions) {
                foreach ($actions as $action => $allowed) {
                    $privStmt->execute([$userId, $resource, $action, $allowed ? 1 : 0]);
                }
            }
        }
        
        jsonResponse(true, ['message' => 'User updated successfully']);
        break;
        
    case 'DELETE':
        requirePrivilege('userManagement', 'delete');
        
        $db = getDB();
        
        if ($userId) {
            // Delete single user
            $stmt = $db->prepare("SELECT id FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            if (!$stmt->fetch()) {
                jsonResponse(false, null, 'User not found', 404);
            }
            
            $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            
            jsonResponse(true, ['message' => 'User deleted successfully']);
        } else {
            // Delete ALL users (dangerous!)
            $stmt = $db->query("SELECT COUNT(*) as count FROM users");
            $count = $stmt->fetch()['count'];
            
            $db->query("DELETE FROM user_privileges");
            $db->query("DELETE FROM users");
            
            jsonResponse(true, [
                'message' => "Deleted {$count} users",
                'count' => $count
            ]);
        }
        break;
        
    default:
        jsonResponse(false, null, 'Method not allowed', 405);
}
?>
