<?php
// Records management endpoints

$method = $_SERVER['REQUEST_METHOD'];
$input = getJsonInput();
$recordId = isset($segments[3]) ? (int)$segments[3] : null;

switch ($method) {
    case 'GET':
        if ($recordId) {
            // Get single record
            requirePrivilege('records', 'view');
            
            $db = getDB();
            $stmt = $db->prepare("
                SELECT r.*, 
                    CONCAT(u.first_name, ' ', u.last_name) as creator_name,
                    CONCAT(a.first_name, ' ', a.last_name) as assignee_name
                FROM records r
                LEFT JOIN users u ON r.created_by = u.id
                LEFT JOIN users a ON r.assigned_to = a.id
                WHERE r.id = ? AND r.is_deleted = 0
            ");
            $stmt->execute([$recordId]);
            $record = $stmt->fetch();
            
            if (!$record) {
                jsonResponse(false, null, 'Record not found', 404);
            }
            
            // Get type-specific details
            switch ($record['type']) {
                case 'incident':
                    $stmt = $db->prepare("SELECT * FROM incident_details WHERE record_id = ?");
                    $stmt->execute([$recordId]);
                    $record['incidentDetails'] = $stmt->fetch();
                    break;
                    
                case 'visitor':
                    $stmt = $db->prepare("SELECT * FROM visitor_details WHERE record_id = ?");
                    $stmt->execute([$recordId]);
                    $record['visitorDetails'] = $stmt->fetch();
                    break;
                    
                case 'patrol':
                    $stmt = $db->prepare("SELECT * FROM patrol_details WHERE record_id = ?");
                    $stmt->execute([$recordId]);
                    $record['patrolDetails'] = $stmt->fetch();
                    break;
                    
                case 'asset':
                    $stmt = $db->prepare("SELECT * FROM asset_details WHERE record_id = ?");
                    $stmt->execute([$recordId]);
                    $record['assetDetails'] = $stmt->fetch();
                    break;
            }
            
            // Get updates
            $stmt = $db->prepare("
                SELECT ru.*, CONCAT(u.first_name, ' ', u.last_name) as user_name
                FROM record_updates ru
                LEFT JOIN users u ON ru.user_id = u.id
                WHERE ru.record_id = ?
                ORDER BY ru.created_at DESC
            ");
            $stmt->execute([$recordId]);
            $record['updates'] = $stmt->fetchAll();
            
            jsonResponse(true, $record);
            
        } else {
            // List records with filters
            requirePrivilege('records', 'view');
            
            $db = getDB();
            
            $type = $_GET['type'] ?? null;
            $status = $_GET['status'] ?? null;
            $priority = $_GET['priority'] ?? null;
            
            $sql = "
                SELECT r.*, 
                    CONCAT(u.first_name, ' ', u.last_name) as creator_name,
                    CONCAT(a.first_name, ' ', a.last_name) as assignee_name
                FROM records r
                LEFT JOIN users u ON r.created_by = u.id
                LEFT JOIN users a ON r.assigned_to = a.id
                WHERE r.is_deleted = 0
            ";
            $params = [];
            
            if ($type) {
                $sql .= " AND r.type = ?";
                $params[] = $type;
            }
            if ($status) {
                $sql .= " AND r.status = ?";
                $params[] = $status;
            }
            if ($priority) {
                $sql .= " AND r.priority = ?";
                $params[] = $priority;
            }
            
            $sql .= " ORDER BY r.occurred_at DESC";
            
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $records = $stmt->fetchAll();
            
            jsonResponse(true, ['count' => count($records), 'data' => $records]);
        }
        break;
        
    case 'POST':
        // Create record
        requirePrivilege('records', 'add');
        $user = requireAuth();
        
        // Validation
        if (empty($input['title']) || empty($input['type'])) {
            jsonResponse(false, null, 'Title and type are required', 400);
        }
        
        $db = getDB();
        
        // Insert main record
        $stmt = $db->prepare("
            INSERT INTO records (
                title, description, type, status, priority,
                location_site, location_building, location_floor, location_room,
                occurred_at, created_by, assigned_to
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $input['title'],
            $input['description'] ?? null,
            $input['type'],
            $input['status'] ?? 'open',
            $input['priority'] ?? 'medium',
            $input['location']['site'] ?? null,
            $input['location']['building'] ?? null,
            $input['location']['floor'] ?? null,
            $input['location']['room'] ?? null,
            $input['occurredAt'] ?? date('Y-m-d H:i:s'),
            $user['id'],
            $input['assignedTo'] ?? null
        ]);
        
        $newRecordId = $db->lastInsertId();
        
        // Insert type-specific details
        switch ($input['type']) {
            case 'incident':
                if (!empty($input['incidentDetails'])) {
                    $stmt = $db->prepare("
                        INSERT INTO incident_details 
                        (record_id, category, severity, injuries, property_damage, police_report_number, insurance_claim)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $newRecordId,
                        $input['incidentDetails']['category'] ?? null,
                        $input['incidentDetails']['severity'] ?? null,
                        $input['incidentDetails']['injuries'] ?? 0,
                        $input['incidentDetails']['propertyDamage'] ?? 0,
                        $input['incidentDetails']['policeReportNumber'] ?? null,
                        $input['incidentDetails']['insuranceClaim'] ?? 0
                    ]);
                }
                break;
                
            case 'visitor':
                if (!empty($input['visitorDetails'])) {
                    $stmt = $db->prepare("
                        INSERT INTO visitor_details
                        (record_id, visitor_name, visitor_id, visitor_company, host_name, host_department, purpose, entry_time, exit_time, vehicle_number, badge_number)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $newRecordId,
                        $input['visitorDetails']['visitorName'] ?? null,
                        $input['visitorDetails']['visitorId'] ?? null,
                        $input['visitorDetails']['visitorCompany'] ?? null,
                        $input['visitorDetails']['hostName'] ?? null,
                        $input['visitorDetails']['hostDepartment'] ?? null,
                        $input['visitorDetails']['purpose'] ?? null,
                        $input['visitorDetails']['entryTime'] ?? null,
                        $input['visitorDetails']['exitTime'] ?? null,
                        $input['visitorDetails']['vehicleNumber'] ?? null,
                        $input['visitorDetails']['badgeNumber'] ?? null
                    ]);
                }
                break;
        }
        
        jsonResponse(true, ['id' => $newRecordId, 'message' => 'Record created'], null, 201);
        break;
        
    case 'PATCH':
        // Update record
        requirePrivilege('records', 'edit');
        
        if (!$recordId) {
            jsonResponse(false, null, 'Record ID required', 400);
        }
        
        $db = getDB();
        
        // Check if exists
        $stmt = $db->prepare("SELECT id FROM records WHERE id = ? AND is_deleted = 0");
        $stmt->execute([$recordId]);
        if (!$stmt->fetch()) {
            jsonResponse(false, null, 'Record not found', 404);
        }
        
        // Build update
        $updates = [];
        $params = [];
        
        if (isset($input['title'])) {
            $updates[] = "title = ?";
            $params[] = $input['title'];
        }
        if (isset($input['description'])) {
            $updates[] = "description = ?";
            $params[] = $input['description'];
        }
        if (isset($input['status'])) {
            $updates[] = "status = ?";
            $params[] = $input['status'];
            if ($input['status'] === 'resolved') {
                $updates[] = "resolved_at = NOW()";
            }
        }
        if (isset($input['priority'])) {
            $updates[] = "priority = ?";
            $params[] = $input['priority'];
        }
        if (isset($input['assignedTo'])) {
            $updates[] = "assigned_to = ?";
            $params[] = $input['assignedTo'];
        }
        
        if (empty($updates)) {
            jsonResponse(false, null, 'No fields to update', 400);
        }
        
        $params[] = $recordId;
        $sql = "UPDATE records SET " . implode(', ', $updates) . " WHERE id = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        
        // Add update log
        if (!empty($input['updateMessage'])) {
            $user = requireAuth();
            $stmt = $db->prepare("
                INSERT INTO record_updates (record_id, user_id, message, status_change)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([
                $recordId,
                $user['id'],
                $input['updateMessage'],
                $input['status'] ?? null
            ]);
        }
        
        jsonResponse(true, ['message' => 'Record updated successfully']);
        break;
        
    case 'DELETE':
        // Soft delete record
        requirePrivilege('records', 'delete');
        $user = requireAuth();
        
        if (!$recordId) {
            jsonResponse(false, null, 'Record ID required', 400);
        }
        
        $db = getDB();
        
        $stmt = $db->prepare("
            UPDATE records 
            SET is_deleted = 1, 
                deleted_at = NOW(),
                deleted_by = ?,
                deletion_reason = ?,
                status = 'deleted'
            WHERE id = ?
        ");
        $stmt->execute([
            $user['id'],
            $input['reason'] ?? 'No reason provided',
            $recordId
        ]);
        
        jsonResponse(true, ['message' => 'Record deleted successfully']);
        break;
        
    default:
        jsonResponse(false, null, 'Method not allowed', 405);
}
?>
