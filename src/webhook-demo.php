<?php
/**
 * Adobe Learning Manager Webhook Demo
 * 
 * Simple webhook receiver for compliance tracking demonstration
 */

// Enable error logging
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/webhook-errors.log');

// Create logs directory if not exists
if (!is_dir(__DIR__ . '/../logs')) {
    mkdir(__DIR__ . '/../logs', 0755, true);
}

// Database setup
$db = new SQLite3(__DIR__ . '/../data/compliance.db');

// Create tables if not exists
$db->exec('
    CREATE TABLE IF NOT EXISTS compliance_tracking (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id TEXT NOT NULL,
        course_id TEXT NOT NULL,
        event_type TEXT NOT NULL,
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

// Process webhook based on event type
if (isset($data['eventName'])) {
    processWebhook($db, $data);
}

function processWebhook($db, $data) {
    switch($data['eventName']) {
        case 'COURSE_ENROLLMENT_BATCH':
            handleEnrollment($db, $data);
            break;
        case 'LEARNER_PROGRESS':
            handleProgress($db, $data);
            break;
        case 'COURSE_COMPLETION':
        case 'LEARNER_COMPLETION':
            handleCompletion($db, $data);
            break;
        default:
            logActivity("Unknown event: " . $data['eventName']);
    }
}

function handleEnrollment($db, $data) {
    $userId = $data['userId'] ?? 'unknown';
    $courseId = $data['learningObjectId'] ?? 'unknown';
    
    // Calculate deadline (30 days from now)
    $deadline = date('Y-m-d H:i:s', strtotime('+30 days'));
    
    // Insert enrollment record
    $stmt = $db->prepare('
        INSERT OR REPLACE INTO compliance_tracking 
        (user_id, course_id, event_type, enrollment_date, deadline_date, status, raw_event)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ');
    
    $stmt->bindValue(1, $userId);
    $stmt->bindValue(2, $courseId);
    $stmt->bindValue(3, 'enrollment');
    $stmt->bindValue(4, date('Y-m-d H:i:s'));
    $stmt->bindValue(5, $deadline);
    $stmt->bindValue(6, 'enrolled');
    $stmt->bindValue(7, json_encode($data));
    $stmt->execute();
    
    // Schedule reminder SMS (demo - not actually sent)
    scheduleDemoSMS($db, $userId, $courseId, 7);  // 7 days before
    scheduleDemoSMS($db, $userId, $courseId, 3);  // 3 days before
    scheduleDemoSMS($db, $userId, $courseId, 1);  // 1 day before
    
    logActivity("ENROLLED: User $userId in course $courseId. Deadline: $deadline");
}

function handleProgress($db, $data) {
    $userId = $data['userId'] ?? 'unknown';
    $courseId = $data['learningObjectId'] ?? 'unknown';
    $progress = $data['progress'] ?? 0;
    
    $stmt = $db->prepare('
        UPDATE compliance_tracking 
        SET progress = ?, status = "in_progress"
        WHERE user_id = ? AND course_id = ?
    ');
    
    $stmt->bindValue(1, $progress);
    $stmt->bindValue(2, $userId);
    $stmt->bindValue(3, $courseId);
    $stmt->execute();
    
    logActivity("PROGRESS: User $userId at $progress% for course $courseId");
}

function handleCompletion($db, $data) {
    $userId = $data['userId'] ?? 'unknown';
    $courseId = $data['learningObjectId'] ?? 'unknown';
    
    $stmt = $db->prepare('
        UPDATE compliance_tracking 
        SET status = "completed", completion_date = ?, progress = 100
        WHERE user_id = ? AND course_id = ?
    ');
    
    $stmt->bindValue(1, date('Y-m-d H:i:s'));
    $stmt->bindValue(2, $userId);
    $stmt->bindValue(3, $courseId);
    $stmt->execute();
    
    // Cancel pending SMS
    $stmt = $db->prepare('
        UPDATE sms_queue 
        SET status = "cancelled"
        WHERE phone LIKE ? AND status = "pending"
    ');
    $stmt->bindValue(1, '%' . $userId . '%');
    $stmt->execute();
    
    logActivity("COMPLETED: User $userId completed course $courseId");
}

function scheduleDemoSMS($db, $userId, $courseId, $daysBefore) {
    // For demo, use fake manager phone
    $managerPhone = '+1555' . substr(md5($userId), 0, 7);
    
    // Calculate when to send
    $sendDate = date('Y-m-d H:i:s', strtotime("+". (30 - $daysBefore) . " days"));
    
    $message = match($daysBefore) {
        7 => "Reminder: $userId has 7 days to complete compliance training",
        3 => "URGENT: $userId has 3 days left for compliance training",
        1 => "FINAL WARNING: $userId must complete training by tomorrow!",
        default => "Compliance reminder for $userId"
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