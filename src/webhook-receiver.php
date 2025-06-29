<?php
/**
 * Simple Webhook Receiver for Adobe Learning Manager
 * 
 * This script receives webhook notifications from ALM and logs them
 * You can test this locally using ngrok or deploy it to a web server
 */

// Set headers for JSON response
header('Content-Type: application/json');

// Get the raw POST data
$input = file_get_contents('php://input');
$data = json_decode($input, true);

// Log file for webhook events
$logFile = 'webhook-events.log';

// Create log entry
$logEntry = [
    'timestamp' => date('Y-m-d H:i:s'),
    'headers' => getallheaders(),
    'body' => $data,
    'raw_input' => $input
];

// Write to log file
file_put_contents($logFile, json_encode($logEntry) . PHP_EOL, FILE_APPEND | LOCK_EX);

// Check if this is a verification request from ALM
if (isset($data['challenge'])) {
    // ALM sends a challenge parameter during webhook setup to verify the endpoint
    echo json_encode(['challenge' => $data['challenge']]);
    exit;
}

// Process different event types
if (isset($data['event'])) {
    $eventType = $data['event'];
    
    switch ($eventType) {
        case 'LEARNER_ENROLLMENT':
            processEnrollment($data);
            break;
            
        case 'LEARNER_PROGRESS':
            processProgress($data);
            break;
            
        case 'LEARNER_COMPLETION':
            processCompletion($data);
            break;
            
        case 'LEARNING_OBJECT_DRAFT':
            processDraftCreation($data);
            break;
            
        case 'LEARNING_OBJECT_MODIFICATION':
            processModification($data);
            break;
            
        default:
            // Log unknown event type
            error_log("Unknown event type: $eventType");
    }
}

// Always respond with 200 OK to acknowledge receipt
http_response_code(200);
echo json_encode(['status' => 'received']);

/**
 * Process enrollment events
 */
function processEnrollment($data) {
    // Extract relevant information
    $userId = $data['userId'] ?? 'unknown';
    $objectId = $data['learningObjectId'] ?? 'unknown';
    $objectType = $data['learningObjectType'] ?? 'unknown';
    
    // You could send a Discord notification here
    $message = "New enrollment: User $userId enrolled in $objectType ($objectId)";
    error_log($message);
    
    // Add your business logic here
    // e.g., update local database, send notifications, etc.
}

/**
 * Process progress update events
 */
function processProgress($data) {
    $userId = $data['userId'] ?? 'unknown';
    $progress = $data['progress'] ?? 0;
    $objectId = $data['learningObjectId'] ?? 'unknown';
    
    $message = "Progress update: User $userId is at $progress% for $objectId";
    error_log($message);
}

/**
 * Process completion events
 */
function processCompletion($data) {
    $userId = $data['userId'] ?? 'unknown';
    $objectId = $data['learningObjectId'] ?? 'unknown';
    $completionDate = $data['dateCompleted'] ?? date('Y-m-d');
    
    $message = "Completion: User $userId completed $objectId on $completionDate";
    error_log($message);
    
    // You could trigger certificate generation, send congratulations email, etc.
}

/**
 * Process draft creation events
 */
function processDraftCreation($data) {
    $objectId = $data['learningObjectId'] ?? 'unknown';
    $objectType = $data['learningObjectType'] ?? 'unknown';
    
    $message = "Draft created: New $objectType draft with ID $objectId";
    error_log($message);
}

/**
 * Process modification events
 */
function processModification($data) {
    $objectId = $data['learningObjectId'] ?? 'unknown';
    $objectType = $data['learningObjectType'] ?? 'unknown';
    
    $message = "Modified: $objectType with ID $objectId was updated";
    error_log($message);
}

/**
 * Optional: Send notification to Discord
 * Similar to your authentication webhook
 */
function sendDiscordNotification($message) {
    $webhookUrl = 'YOUR_DISCORD_WEBHOOK_URL'; // Replace with your Discord webhook
    
    $data = [
        'content' => $message,
        'username' => 'ALM Webhook Bot'
    ];
    
    $options = [
        'http' => [
            'header' => "Content-type: application/json\r\n",
            'method' => 'POST',
            'content' => json_encode($data)
        ]
    ];
    
    $context = stream_context_create($options);
    @file_get_contents($webhookUrl, false, $context);
}