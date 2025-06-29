# EC2 Webhook Demo Setup for ALM Compliance Tracking

## Quick Demo Architecture
```
ALM → EC2 (Webhook Endpoint) → Local SQLite → Console/Log SMS
                                     ↓
                              (Future: Real SMS)
```

## Step 1: EC2 Server Setup

### Install Required Software
```bash
# Update system
sudo apt update && sudo apt upgrade -y

# Install PHP and SQLite
sudo apt install -y php php-cli php-sqlite3 php-curl sqlite3

# Install Composer (for dependencies)
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# Install nginx (if not using Apache)
sudo apt install -y nginx
```

### Create Project Directory
```bash
# Create webhook directory
sudo mkdir -p /var/www/alm-webhooks
sudo chown -R $USER:www-data /var/www/alm-webhooks
cd /var/www/alm-webhooks
```

## Step 2: Simple Webhook Receiver

### Create the Main Webhook Handler
```php
<?php
// /var/www/alm-webhooks/webhook.php

// Enable error logging for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/webhook-errors.log');

// Database setup
$db = new SQLite3(__DIR__ . '/compliance.db');

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

// Log raw webhook for debugging
file_put_contents(__DIR__ . '/webhook-log.json', 
    date('[Y-m-d H:i:s] ') . $input . PHP_EOL, 
    FILE_APPEND
);

// Quick response to ALM
http_response_code(200);
echo json_encode(['status' => 'received']);

// Process webhook based on event type
if (isset($data['eventName'])) {
    switch($data['eventName']) {
        case 'COURSE_ENROLLMENT_BATCH':
            handleEnrollment($db, $data);
            break;
        case 'LEARNER_PROGRESS':
            handleProgress($db, $data);
            break;
        case 'COURSE_COMPLETION':
            handleCompletion($db, $data);
            break;
    }
}

function handleEnrollment($db, $data) {
    // Calculate deadline (30 days from now)
    $deadline = date('Y-m-d H:i:s', strtotime('+30 days'));
    
    // Insert enrollment record
    $stmt = $db->prepare('
        INSERT OR REPLACE INTO compliance_tracking 
        (user_id, course_id, event_type, enrollment_date, deadline_date, status, raw_event)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ');
    
    $stmt->bindValue(1, $data['userId']);
    $stmt->bindValue(2, $data['learningObjectId']);
    $stmt->bindValue(3, 'enrollment');
    $stmt->bindValue(4, date('Y-m-d H:i:s'));
    $stmt->bindValue(5, $deadline);
    $stmt->bindValue(6, 'enrolled');
    $stmt->bindValue(7, json_encode($data));
    $stmt->execute();
    
    // Schedule reminder SMS
    scheduleSMS($db, $data['userId'], $data['learningObjectId'], 7);  // 7 days before
    scheduleSMS($db, $data['userId'], $data['learningObjectId'], 3);  // 3 days before
    scheduleSMS($db, $data['userId'], $data['learningObjectId'], 1);  // 1 day before
    
    logActivity("ENROLLED: User {$data['userId']} in course {$data['learningObjectId']}. Deadline: $deadline");
}

function handleProgress($db, $data) {
    $progress = $data['progress'] ?? 0;
    
    $stmt = $db->prepare('
        UPDATE compliance_tracking 
        SET progress = ?, status = "in_progress"
        WHERE user_id = ? AND course_id = ?
    ');
    
    $stmt->bindValue(1, $progress);
    $stmt->bindValue(2, $data['userId']);
    $stmt->bindValue(3, $data['learningObjectId']);
    $stmt->execute();
    
    logActivity("PROGRESS: User {$data['userId']} at {$progress}% for course {$data['learningObjectId']}");
}

function handleCompletion($db, $data) {
    $stmt = $db->prepare('
        UPDATE compliance_tracking 
        SET status = "completed", completion_date = ?, progress = 100
        WHERE user_id = ? AND course_id = ?
    ');
    
    $stmt->bindValue(1, date('Y-m-d H:i:s'));
    $stmt->bindValue(2, $data['userId']);
    $stmt->bindValue(3, $data['learningObjectId']);
    $stmt->execute();
    
    // Cancel pending SMS
    $stmt = $db->prepare('
        UPDATE sms_queue 
        SET status = "cancelled"
        WHERE phone LIKE ? AND status = "pending"
    ');
    $stmt->bindValue(1, '%' . $data['userId'] . '%');
    $stmt->execute();
    
    logActivity("COMPLETED: User {$data['userId']} completed course {$data['learningObjectId']}");
}

function scheduleSMS($db, $userId, $courseId, $daysBefore) {
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
}

function logActivity($message) {
    file_put_contents(__DIR__ . '/activity.log', 
        date('[Y-m-d H:i:s] ') . $message . PHP_EOL, 
        FILE_APPEND
    );
}
```

### Create SMS Processor (Cron Job)
```php
<?php
// /var/www/alm-webhooks/process-sms.php

$db = new SQLite3(__DIR__ . '/compliance.db');

// Check for overdue training
$overdueStmt = $db->prepare('
    SELECT * FROM compliance_tracking 
    WHERE status != "completed" 
    AND deadline_date < datetime("now")
    AND alerts_sent NOT LIKE "%overdue%"
');

$results = $overdueStmt->execute();
while ($row = $results->fetchArray(SQLITE3_ASSOC)) {
    $message = "OVERDUE ALERT: {$row['user_id']} missed compliance deadline!";
    
    // For demo, just log it
    echo date('[Y-m-d H:i:s] ') . $message . PHP_EOL;
    file_put_contents(__DIR__ . '/sms-demo.log', 
        date('[Y-m-d H:i:s] ') . "TO: Manager of {$row['user_id']} - $message" . PHP_EOL, 
        FILE_APPEND
    );
    
    // Mark as sent
    $alerts = json_decode($row['alerts_sent'], true) ?: [];
    $alerts[] = 'overdue';
    
    $updateStmt = $db->prepare('
        UPDATE compliance_tracking 
        SET alerts_sent = ? 
        WHERE id = ?
    ');
    $updateStmt->bindValue(1, json_encode($alerts));
    $updateStmt->bindValue(2, $row['id']);
    $updateStmt->execute();
}

// Process scheduled SMS
$smsStmt = $db->prepare('
    SELECT * FROM sms_queue 
    WHERE status = "pending" 
    AND scheduled_for <= datetime("now")
');

$results = $smsStmt->execute();
while ($row = $results->fetchArray(SQLITE3_ASSOC)) {
    // For demo, log instead of sending
    echo "SMS TO {$row['phone']}: {$row['message']}\n";
    file_put_contents(__DIR__ . '/sms-demo.log', 
        date('[Y-m-d H:i:s] ') . "TO: {$row['phone']} - {$row['message']}" . PHP_EOL, 
        FILE_APPEND
    );
    
    // Mark as sent
    $updateStmt = $db->prepare('
        UPDATE sms_queue 
        SET status = "sent", sent_at = datetime("now") 
        WHERE id = ?
    ');
    $updateStmt->bindValue(1, $row['id']);
    $updateStmt->execute();
}
```

### Create Status Dashboard
```php
<?php
// /var/www/alm-webhooks/dashboard.php
$db = new SQLite3(__DIR__ . '/compliance.db');
?>
<!DOCTYPE html>
<html>
<head>
    <title>ALM Compliance Tracking Demo</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        table { border-collapse: collapse; width: 100%; margin: 20px 0; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background: #f4f4f4; }
        .overdue { background: #ffdddd; }
        .completed { background: #ddffdd; }
        .pending { background: #ffffdd; }
        .log { background: #f0f0f0; padding: 10px; font-family: monospace; 
               white-space: pre-wrap; max-height: 200px; overflow-y: auto; }
    </style>
    <meta http-equiv="refresh" content="30">
</head>
<body>
    <h1>ALM Compliance Tracking Demo Dashboard</h1>
    
    <h2>Compliance Status</h2>
    <table>
        <tr>
            <th>User ID</th>
            <th>Course ID</th>
            <th>Status</th>
            <th>Progress</th>
            <th>Deadline</th>
            <th>Days Left</th>
        </tr>
        <?php
        $stmt = $db->query('SELECT *, 
            CAST((julianday(deadline_date) - julianday("now")) AS INTEGER) as days_left 
            FROM compliance_tracking ORDER BY deadline_date');
        
        while ($row = $stmt->fetchArray(SQLITE3_ASSOC)) {
            $class = '';
            if ($row['status'] == 'completed') $class = 'completed';
            elseif ($row['days_left'] < 0) $class = 'overdue';
            elseif ($row['days_left'] < 3) $class = 'pending';
            
            echo "<tr class='$class'>";
            echo "<td>{$row['user_id']}</td>";
            echo "<td>{$row['course_id']}</td>";
            echo "<td>{$row['status']}</td>";
            echo "<td>{$row['progress']}%</td>";
            echo "<td>{$row['deadline_date']}</td>";
            echo "<td>{$row['days_left']}</td>";
            echo "</tr>";
        }
        ?>
    </table>
    
    <h2>Scheduled SMS Queue</h2>
    <table>
        <tr>
            <th>Phone</th>
            <th>Message</th>
            <th>Scheduled For</th>
            <th>Status</th>
        </tr>
        <?php
        $stmt = $db->query('SELECT * FROM sms_queue ORDER BY scheduled_for DESC LIMIT 20');
        while ($row = $stmt->fetchArray(SQLITE3_ASSOC)) {
            echo "<tr>";
            echo "<td>{$row['phone']}</td>";
            echo "<td>{$row['message']}</td>";
            echo "<td>{$row['scheduled_for']}</td>";
            echo "<td>{$row['status']}</td>";
            echo "</tr>";
        }
        ?>
    </table>
    
    <h2>Recent Webhook Activity</h2>
    <div class="log">
        <?php echo htmlspecialchars(file_get_contents(__DIR__ . '/activity.log') ?: 'No activity yet'); ?>
    </div>
    
    <h2>Demo SMS Log</h2>
    <div class="log">
        <?php echo htmlspecialchars(file_get_contents(__DIR__ . '/sms-demo.log') ?: 'No SMS sent yet'); ?>
    </div>
    
    <p><small>Page auto-refreshes every 30 seconds</small></p>
</body>
</html>
```

## Step 3: Configure Nginx

```nginx
# /etc/nginx/sites-available/alm-webhooks
server {
    listen 80;
    server_name your-ec2-public-ip;
    
    root /var/www/alm-webhooks;
    index dashboard.php;
    
    location / {
        try_files $uri $uri/ =404;
    }
    
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
    }
    
    location /webhook {
        try_files $uri /webhook.php?$query_string;
    }
}
```

Enable the site:
```bash
sudo ln -s /etc/nginx/sites-available/alm-webhooks /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl restart nginx
```

## Step 4: Set Up Cron Job

```bash
# Add to crontab
crontab -e

# Add these lines:
# Check for overdue training every hour
0 * * * * cd /var/www/alm-webhooks && php process-sms.php >> cron.log 2>&1

# Process SMS queue every 5 minutes
*/5 * * * * cd /var/www/alm-webhooks && php process-sms.php >> cron.log 2>&1
```

## Step 5: Configure ALM Webhook

1. Log into ALM as Integration Admin
2. Go to Webhooks → Add Webhook
3. Configure:
   - Name: "Compliance Tracking Demo"
   - URL: `http://your-ec2-ip/webhook`
   - Events: COURSE_ENROLLMENT_BATCH, LEARNER_PROGRESS, COURSE_COMPLETION
   - Authentication: None (for demo)

## Step 6: Test the System

### Manual Test with cURL
```bash
# Test enrollment
curl -X POST http://localhost/webhook \
  -H "Content-Type: application/json" \
  -d '{
    "eventName": "COURSE_ENROLLMENT_BATCH",
    "userId": "test-user-001",
    "learningObjectId": "safety-training-2024",
    "eventTime": 1703001600000
  }'

# Test progress
curl -X POST http://localhost/webhook \
  -H "Content-Type: application/json" \
  -d '{
    "eventName": "LEARNER_PROGRESS",
    "userId": "test-user-001",
    "learningObjectId": "safety-training-2024",
    "progress": 50
  }'

# Test completion
curl -X POST http://localhost/webhook \
  -H "Content-Type: application/json" \
  -d '{
    "eventName": "COURSE_COMPLETION",
    "userId": "test-user-001",
    "learningObjectId": "safety-training-2024"
  }'
```

### View Results
- Dashboard: `http://your-ec2-ip/`
- Activity Log: `http://your-ec2-ip/activity.log`
- SMS Demo Log: `http://your-ec2-ip/sms-demo.log`
- Raw Webhooks: `http://your-ec2-ip/webhook-log.json`

## Step 7: Make it Production-Ready

### Add Real SMS (Twilio)
```bash
composer require twilio/sdk
```

```php
// Replace the demo SMS logging with:
use Twilio\Rest\Client;

$twilio = new Client($account_sid, $auth_token);
$twilio->messages->create(
    $row['phone'],
    ['from' => $twilio_number, 'body' => $row['message']]
);
```

### Add Security
```nginx
# Use HTTPS
# Add webhook signature validation
# Limit access by IP
```

## Demo Talking Points

1. **Real-time Tracking**: Show enrollment creating deadline
2. **Progress Updates**: Show progress bar moving
3. **SMS Queue**: Show scheduled messages
4. **Deadline Alerts**: Force overdue by changing dates
5. **Completion**: Show how it cancels pending alerts

This gives you a complete working demo on your EC2 instance that you can show without actually sending SMS messages!