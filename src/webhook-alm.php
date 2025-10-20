<?php
/**
 * Adobe Learning Manager Webhook Handler
 * Updated to handle ALM's actual webhook payload structure
 */

// Enable error logging
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/webhook-errors.log');

// Create directories if not exists
if (!is_dir(__DIR__ . '/../logs')) {
    mkdir(__DIR__ . '/../logs', 0777, true);
}
if (!is_dir(__DIR__ . '/../data')) {
    mkdir(__DIR__ . '/../data', 0777, true);
}

// Database setup
$db = new SQLite3(__DIR__ . '/../data/compliance.db');

// Create tables if not exists
$db->exec('
    CREATE TABLE IF NOT EXISTS compliance_tracking (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        account_id TEXT NOT NULL,
        event_id TEXT UNIQUE,
        event_name TEXT NOT NULL,
        user_id TEXT NOT NULL,
        course_id TEXT NOT NULL,
        instance_id TEXT,
        enrollment_source TEXT,
        enrollment_date DATETIME,
        deadline_date DATETIME,
        completion_date DATETIME,
        progress INTEGER DEFAULT 0,
        status TEXT DEFAULT "enrolled",
        manager_phone TEXT,
        alerts_sent TEXT DEFAULT "[]",
        raw_event TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )
');

$db->exec('
    CREATE TABLE IF NOT EXISTS webhook_events (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        account_id TEXT NOT NULL,
        event_id TEXT UNIQUE,
        event_name TEXT NOT NULL,
        event_timestamp DATETIME,
        event_info TEXT,
        raw_payload TEXT,
        processed BOOLEAN DEFAULT FALSE,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )
');

$db->exec('
    CREATE TABLE IF NOT EXISTS sms_queue (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        phone TEXT NOT NULL,
        message TEXT NOT NULL,
        scheduled_for DATETIME NOT NULL,
        sent_at DATETIME,
        status TEXT DEFAULT "pending",
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )
');

// Get webhook data
$input = file_get_contents('php://input');
$data = json_decode($input, true);

// Log raw webhook
file_put_contents(__DIR__ . '/../logs/webhook-raw.log', 
    date('[Y-m-d H:i:s] ') . $input . PHP_EOL, 
    FILE_APPEND
);

// Handle verification challenge from ALM
if (isset($data['challenge'])) {
    echo json_encode(['challenge' => $data['challenge']]);
    exit;
}

// Quick response to ALM
http_response_code(200);
echo json_encode(['status' => 'received']);

// Process webhook if we have events
if (isset($data['events']) && is_array($data['events'])) {
    $accountId = $data['accountId'] ?? 'unknown';
    
    foreach ($data['events'] as $event) {
        processEvent($db, $event, $accountId);
    }
}

function processEvent($db, $event, $accountId) {
    // Extract event details
    $eventId = $event['eventId'] ?? uniqid();
    $eventName = $event['eventName'] ?? 'UNKNOWN';
    $timestamp = $event['timestamp'] ?? time() * 1000;
    $eventData = $event['data'] ?? [];
    
    // Store raw event
    $stmt = $db->prepare('
        INSERT OR IGNORE INTO webhook_events 
        (account_id, event_id, event_name, event_timestamp, event_info, raw_payload)
        VALUES (?, ?, ?, ?, ?, ?)
    ');
    
    $stmt->bindValue(1, $accountId);
    $stmt->bindValue(2, $eventId);
    $stmt->bindValue(3, $eventName);
    $stmt->bindValue(4, date('Y-m-d H:i:s', $timestamp / 1000));
    $stmt->bindValue(5, $event['eventInfo'] ?? '');
    $stmt->bindValue(6, json_encode($event));
    $stmt->execute();
    
    // Process based on event type
    switch ($eventName) {
        case 'COURSE_ENROLLMENT':
        case 'COURSE_ENROLLMENT_BATCH':
            handleEnrollment($db, $eventData, $accountId, $eventId);
            break;
            
        case 'LEARNER_PROGRESS':
        case 'COURSE_PROGRESS':
            handleProgress($db, $eventData, $accountId, $eventId);
            break;
            
        case 'COURSE_COMPLETION':
        case 'LEARNER_COMPLETION':
            handleCompletion($db, $eventData, $accountId, $eventId);
            break;
            
        case 'COURSE_UNENROLLMENT':
        case 'LEARNER_UNENROLLMENT':
            handleUnenrollment($db, $eventData, $accountId, $eventId);
            break;
            
        default:
            logActivity("Unknown event type: $eventName for account $accountId");
    }
}

function handleEnrollment($db, $data, $accountId, $eventId) {
    $userId = $data['userId'] ?? 'unknown';
    $loId = $data['loId'] ?? $data['learningObjectId'] ?? 'unknown';
    $loInstanceId = $data['loInstanceId'] ?? '';
    $enrollmentSource = $data['enrollmentSource'] ?? 'UNKNOWN';
    $dateEnrolled = $data['dateEnrolled'] ?? time();
    
    // Calculate deadline (30 days from enrollment)
    $enrollmentDate = date('Y-m-d H:i:s', $dateEnrolled);
    $deadline = date('Y-m-d H:i:s', strtotime('+30 days', $dateEnrolled));
    
    // Insert or update enrollment record
    $stmt = $db->prepare('
        INSERT OR REPLACE INTO compliance_tracking 
        (account_id, event_id, event_name, user_id, course_id, instance_id, 
         enrollment_source, enrollment_date, deadline_date, status, raw_event)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    
    $stmt->bindValue(1, $accountId);
    $stmt->bindValue(2, $eventId);
    $stmt->bindValue(3, 'enrollment');
    $stmt->bindValue(4, $userId);
    $stmt->bindValue(5, $loId);
    $stmt->bindValue(6, $loInstanceId);
    $stmt->bindValue(7, $enrollmentSource);
    $stmt->bindValue(8, $enrollmentDate);
    $stmt->bindValue(9, $deadline);
    $stmt->bindValue(10, 'enrolled');
    $stmt->bindValue(11, json_encode($data));
    $stmt->execute();
    
    // Schedule reminder SMS (demo - not actually sent)
    scheduleDemoSMS($db, $userId, $loId, 7);  // 7 days before
    scheduleDemoSMS($db, $userId, $loId, 3);  // 3 days before
    scheduleDemoSMS($db, $userId, $loId, 1);  // 1 day before
    
    logActivity("ENROLLED: User $userId in course $loId (Instance: $loInstanceId). Source: $enrollmentSource. Deadline: $deadline");
}

function handleProgress($db, $data, $accountId, $eventId) {
    $userId = $data['userId'] ?? 'unknown';
    $loId = $data['loId'] ?? $data['learningObjectId'] ?? 'unknown';
    $progress = $data['progress'] ?? $data['percentComplete'] ?? 0;
    
    $stmt = $db->prepare('
        UPDATE compliance_tracking 
        SET progress = ?, status = "in_progress"
        WHERE user_id = ? AND course_id = ? AND account_id = ?
    ');
    
    $stmt->bindValue(1, $progress);
    $stmt->bindValue(2, $userId);
    $stmt->bindValue(3, $loId);
    $stmt->bindValue(4, $accountId);
    $stmt->execute();
    
    logActivity("PROGRESS: User $userId at $progress% for course $loId");
}

function handleCompletion($db, $data, $accountId, $eventId) {
    $userId = $data['userId'] ?? 'unknown';
    $loId = $data['loId'] ?? $data['learningObjectId'] ?? 'unknown';
    $completionDate = $data['dateCompleted'] ?? time();
    
    $stmt = $db->prepare('
        UPDATE compliance_tracking 
        SET status = "completed", 
            completion_date = ?, 
            progress = 100
        WHERE user_id = ? AND course_id = ? AND account_id = ?
    ');
    
    $stmt->bindValue(1, date('Y-m-d H:i:s', $completionDate));
    $stmt->bindValue(2, $userId);
    $stmt->bindValue(3, $loId);
    $stmt->bindValue(4, $accountId);
    $stmt->execute();
    
    // Cancel pending SMS
    $stmt = $db->prepare('
        UPDATE sms_queue 
        SET status = "cancelled"
        WHERE phone LIKE ? AND status = "pending"
    ');
    $stmt->bindValue(1, '%' . $userId . '%');
    $stmt->execute();
    
    logActivity("COMPLETED: User $userId completed course $loId");
}

function handleUnenrollment($db, $data, $accountId, $eventId) {
    $userId = $data['userId'] ?? 'unknown';
    $loId = $data['loId'] ?? $data['learningObjectId'] ?? 'unknown';
    
    $stmt = $db->prepare('
        UPDATE compliance_tracking 
        SET status = "unenrolled"
        WHERE user_id = ? AND course_id = ? AND account_id = ?
    ');
    
    $stmt->bindValue(1, $userId);
    $stmt->bindValue(2, $loId);
    $stmt->bindValue(3, $accountId);
    $stmt->execute();
    
    // Cancel pending SMS
    $stmt = $db->prepare('
        UPDATE sms_queue 
        SET status = "cancelled"
        WHERE phone LIKE ? AND status = "pending"
    ');
    $stmt->bindValue(1, '%' . $userId . '%');
    $stmt->execute();
    
    logActivity("UNENROLLED: User $userId from course $loId");
}

function scheduleDemoSMS($db, $userId, $courseId, $daysBefore) {
    // For demo, use fake manager phone
    $managerPhone = '+1555' . substr(md5($userId), 0, 7);
    
    // Calculate when to send
    $sendDate = date('Y-m-d H:i:s', strtotime("+". (30 - $daysBefore) . " days"));
    
    $message = match($daysBefore) {
        7 => "Reminder: User $userId has 7 days to complete compliance training ($courseId)",
        3 => "URGENT: User $userId has 3 days left for compliance training ($courseId)",
        1 => "FINAL WARNING: User $userId must complete training by tomorrow! ($courseId)",
        default => "Compliance reminder for User $userId"
    };
    
    $stmt = $db->prepare('
        INSERT INTO sms_queue (phone, message, scheduled_for)
        VALUES (?, ?, ?)
    ');
    
    $stmt->bindValue(1, $managerPhone);
    $stmt->bindValue(2, $message);
    $stmt->bindValue(3, $sendDate);
    $stmt->execute();
    
    logActivity("Scheduled SMS for $daysBefore days before deadline");
}

function logActivity($message) {
    $logFile = __DIR__ . '/../logs/activity.log';
    file_put_contents($logFile, 
        date('[Y-m-d H:i:s] ') . $message . PHP_EOL, 
        FILE_APPEND
    );
}