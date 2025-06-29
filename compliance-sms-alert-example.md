# SMS Alerts for Missed Compliance Deadlines

## Use Case Overview
Send automated SMS messages to managers when their team members miss mandatory compliance training deadlines.

## Decision Path in Our Tree
```
🏁 What's your need? → 📊 Work with data
📊 How to receive? → 📬 Push notifications  
📬 Which events? → ✅ Completions (or lack thereof!)
→ WEBBY WEBHOOK
```

## Implementation Architecture

```
ALM Webhook → Your Server → SMS Service (Twilio/AWS SNS) → Manager's Phone
     ↓              ↓
  Event Data   Process & Filter
```

## Complete Working Example

### 1. Webhook Handler (PHP)
```php
<?php
// compliance-deadline-webhook.php

require_once 'vendor/autoload.php';
use Twilio\Rest\Client;

class ComplianceDeadlineHandler {
    private $twilio;
    private $db;
    
    public function __construct() {
        // Initialize Twilio
        $this->twilio = new Client(
            getenv('TWILIO_ACCOUNT_SID'),
            getenv('TWILIO_AUTH_TOKEN')
        );
        
        // Database connection
        $this->db = new PDO('mysql:host=localhost;dbname=alm_compliance', 
            getenv('DB_USER'), 
            getenv('DB_PASS')
        );
    }
    
    public function handleWebhook($webhookData) {
        // Acknowledge webhook immediately
        http_response_code(200);
        echo json_encode(['status' => 'received']);
        fastcgi_finish_request();
        
        // Process based on event type
        switch($webhookData['eventName']) {
            case 'COURSE_ENROLLMENT_BATCH':
                $this->trackEnrollment($webhookData);
                break;
                
            case 'LEARNER_PROGRESS':
                $this->updateProgress($webhookData);
                break;
                
            case 'COURSE_COMPLETION':
                $this->markCompleted($webhookData);
                break;
        }
        
        // Check for missed deadlines
        $this->checkDeadlines();
    }
    
    private function trackEnrollment($data) {
        // Record enrollment with deadline
        $stmt = $this->db->prepare("
            INSERT INTO compliance_tracking 
            (user_id, course_id, manager_id, enrolled_date, deadline_date, status)
            VALUES (?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), 'enrolled')
        ");
        
        $managerId = $this->getManagerId($data['userId']);
        $stmt->execute([
            $data['userId'],
            $data['learningObjectId'],
            $managerId
        ]);
    }
    
    private function checkDeadlines() {
        // Find all missed deadlines that haven't been alerted yet
        $overdueTrainings = $this->db->query("
            SELECT 
                ct.*,
                u.name as employee_name,
                u.email as employee_email,
                m.name as manager_name,
                m.phone as manager_phone,
                c.name as course_name,
                DATEDIFF(NOW(), ct.deadline_date) as days_overdue
            FROM compliance_tracking ct
            JOIN users u ON ct.user_id = u.id
            JOIN users m ON ct.manager_id = m.id
            JOIN courses c ON ct.course_id = c.id
            WHERE ct.status != 'completed'
            AND ct.deadline_date < NOW()
            AND ct.alert_sent = 0
            AND ct.deadline_date > DATE_SUB(NOW(), INTERVAL 7 DAY)
        ")->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($overdueTrainings as $training) {
            $this->sendManagerSMS($training);
            $this->markAlertSent($training['id']);
        }
    }
    
    private function sendManagerSMS($training) {
        $message = $this->buildSMSMessage($training);
        
        try {
            $this->twilio->messages->create(
                $training['manager_phone'],
                [
                    'from' => getenv('TWILIO_PHONE_NUMBER'),
                    'body' => $message
                ]
            );
            
            $this->logSMS($training, $message, 'sent');
        } catch (Exception $e) {
            $this->logSMS($training, $message, 'failed', $e->getMessage());
        }
    }
    
    private function buildSMSMessage($training) {
        $daysOverdue = $training['days_overdue'];
        $employeeName = $training['employee_name'];
        $courseName = $training['course_name'];
        
        // Different urgency levels
        if ($daysOverdue >= 5) {
            return "🚨 URGENT: $employeeName is $daysOverdue days overdue for mandatory training: $courseName. Immediate action required!";
        } elseif ($daysOverdue >= 3) {
            return "⚠️ ALERT: $employeeName has missed the deadline for $courseName by $daysOverdue days. Please follow up.";
        } else {
            return "📋 Notice: $employeeName missed the deadline for $courseName. Please ensure completion ASAP.";
        }
    }
}

// Handle incoming webhook
$handler = new ComplianceDeadlineHandler();
$input = json_decode(file_get_contents('php://input'), true);
$handler->handleWebhook($input);
```

### 2. Scheduled Deadline Checker (Cron Job)
```php
<?php
// check-compliance-deadlines.php
// Run this every hour via cron: 0 * * * * php check-compliance-deadlines.php

class ComplianceDeadlineChecker {
    private $smsService;
    private $db;
    
    public function checkUpcomingDeadlines() {
        // Warning SMS 3 days before deadline
        $this->sendWarnings(3, 'warning');
        
        // Urgent SMS 1 day before deadline
        $this->sendWarnings(1, 'urgent');
        
        // Overdue SMS for missed deadlines
        $this->sendOverdueAlerts();
    }
    
    private function sendWarnings($daysBeforeDeadline, $type) {
        $trainings = $this->db->query("
            SELECT * FROM compliance_tracking ct
            JOIN users u ON ct.user_id = u.id
            JOIN users m ON ct.manager_id = m.id
            JOIN courses c ON ct.course_id = c.id
            WHERE ct.status = 'in_progress'
            AND ct.deadline_date = DATE_ADD(CURDATE(), INTERVAL $daysBeforeDeadline DAY)
            AND ct.{$type}_sent = 0
        ")->fetchAll();
        
        foreach ($trainings as $training) {
            $message = $this->buildWarningMessage($training, $daysBeforeDeadline);
            $this->sendSMS($training['manager_phone'], $message);
            $this->markWarningSent($training['id'], $type);
        }
    }
    
    private function sendOverdueAlerts() {
        // Escalating alerts for overdue training
        $escalationSchedule = [
            1 => 'manager',      // Day 1: Manager only
            3 => 'manager',      // Day 3: Manager reminder
            5 => 'director',     // Day 5: Escalate to director
            7 => 'compliance',   // Day 7: Compliance team
            14 => 'executive'    // Day 14: Executive escalation
        ];
        
        foreach ($escalationSchedule as $daysOverdue => $recipient) {
            $this->sendEscalatedAlerts($daysOverdue, $recipient);
        }
    }
}
```

### 3. Database Schema
```sql
CREATE TABLE compliance_tracking (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id VARCHAR(50) NOT NULL,
    course_id VARCHAR(50) NOT NULL,
    manager_id VARCHAR(50) NOT NULL,
    enrolled_date DATETIME NOT NULL,
    deadline_date DATETIME NOT NULL,
    completed_date DATETIME NULL,
    status ENUM('enrolled', 'in_progress', 'completed', 'overdue') DEFAULT 'enrolled',
    progress INT DEFAULT 0,
    alert_sent BOOLEAN DEFAULT FALSE,
    warning_sent BOOLEAN DEFAULT FALSE,
    urgent_sent BOOLEAN DEFAULT FALSE,
    escalation_level INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_deadline (deadline_date, status),
    INDEX idx_user_manager (user_id, manager_id)
);

CREATE TABLE sms_log (
    id INT PRIMARY KEY AUTO_INCREMENT,
    tracking_id INT,
    recipient_phone VARCHAR(20),
    message TEXT,
    status ENUM('sent', 'failed', 'queued'),
    error_message TEXT NULL,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tracking_id) REFERENCES compliance_tracking(id)
);
```

### 4. Smart Escalation Flow
```php
class ComplianceEscalation {
    private $escalationRules = [
        'safety_training' => [
            'deadline_days' => 14,
            'warning_days' => [7, 3, 1],
            'overdue_escalation' => [
                1 => ['manager'],
                3 => ['manager', 'safety_officer'],
                7 => ['director', 'compliance_team'],
                14 => ['vp', 'legal']
            ]
        ],
        'annual_certification' => [
            'deadline_days' => 30,
            'warning_days' => [14, 7, 3],
            'overdue_escalation' => [
                1 => ['manager'],
                7 => ['director'],
                30 => ['hr', 'compliance_team']
            ]
        ]
    ];
    
    public function getEscalationPath($courseType, $daysOverdue) {
        $rules = $this->escalationRules[$courseType] ?? $this->escalationRules['default'];
        
        foreach ($rules['overdue_escalation'] as $day => $recipients) {
            if ($daysOverdue >= $day) {
                $escalateTo = $recipients;
            }
        }
        
        return $escalateTo ?? ['manager'];
    }
}
```

### 5. SMS Message Templates
```php
class SMSTemplates {
    public static function getTemplate($type, $data) {
        $templates = [
            'deadline_warning' => "Hi {manager_name}, {employee_name} has {days_left} days to complete {course_name}. Please remind them.",
            
            'deadline_urgent' => "🚨 URGENT: {employee_name} must complete {course_name} by tomorrow! Compliance at risk.",
            
            'deadline_missed' => "⚠️ {employee_name} missed deadline for {course_name}. Immediate action required. Reply HELP for options.",
            
            'escalation_director' => "ESCALATION: {employee_name} is {days_overdue} days overdue for mandatory {course_name}. Manager notified {times_notified} times.",
            
            'completion_success' => "✅ Good news! {employee_name} completed {course_name} on time. Thank you for your follow-up."
        ];
        
        $template = $templates[$type];
        
        // Replace placeholders
        foreach ($data as $key => $value) {
            $template = str_replace('{' . $key . '}', $value, $template);
        }
        
        return $template;
    }
}
```

## Benefits of Webhook Approach

1. **Real-time Tracking**: Know immediately when someone starts/completes training
2. **Proactive Alerts**: Don't wait for daily reports - alert managers instantly
3. **Smart Escalation**: Automatically escalate based on severity and time
4. **Audit Trail**: Complete log of all notifications sent
5. **Reduced Manual Work**: No need for HR to manually track and chase

## Cost Consideration
- Twilio SMS: ~$0.0075 per message
- For 1000 employees with 10% non-compliance rate = ~100 SMS/month = $0.75
- Much cheaper than compliance violations!

## Testing the Integration
```bash
# Test webhook endpoint
curl -X POST https://your-server.com/webhook/compliance \
  -H "Content-Type: application/json" \
  -d '{
    "eventName": "COURSE_ENROLLMENT_BATCH",
    "userId": "emp123",
    "learningObjectId": "safety-training-2024",
    "eventTime": 1703001600000
  }'
```

This system ensures managers are always aware of compliance risks and can take immediate action to prevent violations!