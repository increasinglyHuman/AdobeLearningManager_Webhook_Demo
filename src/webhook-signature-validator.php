<?php
/**
 * Webhook Signature Validator for Adobe Learning Manager
 * 
 * This script demonstrates how to validate webhook signatures
 * when using signature-based authentication
 */

class ALMWebhookValidator {
    private $secret;
    
    public function __construct($secret) {
        $this->secret = $secret;
    }
    
    /**
     * Validate the webhook signature
     * 
     * @param string $payload The raw request body
     * @param string $signature The signature from headers
     * @return bool
     */
    public function validateSignature($payload, $signature) {
        // ALM likely uses HMAC-SHA256 for signatures
        $expectedSignature = hash_hmac('sha256', $payload, $this->secret);
        
        // Use timing-safe comparison to prevent timing attacks
        return hash_equals($expectedSignature, $signature);
    }
    
    /**
     * Process incoming webhook with validation
     */
    public function processWebhook() {
        // Get headers
        $headers = getallheaders();
        
        // Get raw payload
        $payload = file_get_contents('php://input');
        
        // Check for signature header (header name may vary - check ALM docs)
        $signature = $headers['X-ALM-Signature'] ?? $headers['X-Webhook-Signature'] ?? null;
        
        if ($signature && !$this->validateSignature($payload, $signature)) {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid signature']);
            return false;
        }
        
        // Parse the JSON payload
        $data = json_decode($payload, true);
        
        // Handle verification challenge
        if (isset($data['challenge'])) {
            echo json_encode(['challenge' => $data['challenge']]);
            return true;
        }
        
        // Process the webhook data
        return $this->handleWebhookData($data);
    }
    
    /**
     * Handle the webhook data
     */
    private function handleWebhookData($data) {
        // Log the event
        $this->logEvent($data);
        
        // Process events
        if (isset($data['events']) && is_array($data['events'])) {
            foreach ($data['events'] as $event) {
                $this->processEvent($event);
            }
        }
        
        // Always return 200 OK
        http_response_code(200);
        echo json_encode(['status' => 'processed']);
        return true;
    }
    
    /**
     * Process individual events
     */
    private function processEvent($event) {
        $eventName = $event['eventName'] ?? 'UNKNOWN';
        $eventTime = $event['eventTime'] ?? time();
        $eventData = $event['eventData'] ?? [];
        
        // Convert timestamp to readable date
        $eventDate = date('Y-m-d H:i:s', $eventTime / 1000);
        
        switch ($eventName) {
            case 'COURSE_ENROLLMENT_BATCH':
                $this->handleEnrollment($eventData, $eventDate);
                break;
                
            case 'LEARNER_PROGRESS':
                $this->handleProgress($eventData, $eventDate);
                break;
                
            case 'COURSE_COMPLETION':
                $this->handleCompletion($eventData, $eventDate);
                break;
                
            default:
                error_log("Unhandled event type: $eventName at $eventDate");
        }
    }
    
    /**
     * Handle enrollment events
     */
    private function handleEnrollment($data, $eventDate) {
        // Example: Send welcome email, update local database, etc.
        error_log("Enrollment event at $eventDate: " . json_encode($data));
    }
    
    /**
     * Handle progress events
     */
    private function handleProgress($data, $eventDate) {
        // Example: Update progress tracking, send encouragement, etc.
        error_log("Progress event at $eventDate: " . json_encode($data));
    }
    
    /**
     * Handle completion events
     */
    private function handleCompletion($data, $eventDate) {
        // Example: Generate certificate, update records, send congratulations
        error_log("Completion event at $eventDate: " . json_encode($data));
    }
    
    /**
     * Log events to file
     */
    private function logEvent($data) {
        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'accountId' => $data['accountId'] ?? 'unknown',
            'eventCount' => count($data['events'] ?? []),
            'events' => $data['events'] ?? []
        ];
        
        file_put_contents(
            'webhook-events-validated.log', 
            json_encode($logEntry) . PHP_EOL, 
            FILE_APPEND | LOCK_EX
        );
    }
}

// Usage
$webhookSecret = getenv('ALM_WEBHOOK_SECRET') ?: 'your-webhook-secret';
$validator = new ALMWebhookValidator($webhookSecret);
$validator->processWebhook();