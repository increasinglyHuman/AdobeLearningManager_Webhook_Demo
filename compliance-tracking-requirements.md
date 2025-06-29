# Complete Requirements for Compliance Deadline SMS System

## 1. What You Need from Adobe Learning Manager

### Webhook Events to Subscribe To:
```javascript
// Essential events for compliance tracking
- COURSE_ENROLLMENT_BATCH    // Know when compliance training is assigned
- LEARNER_PROGRESS           // Track progress toward deadline
- COURSE_COMPLETION          // Know when they complete (stop alerts)
- LEARNER_UNENROLLMENT      // If someone is removed from training
```

### ALM Configuration:
1. **Integration Admin Access** to set up webhooks
2. **API Keys** from Appleberry (for any supplemental API calls)
3. **Webhook endpoint URL** (your server that receives events)

## 2. Infrastructure Components

### A. Web Server to Receive Webhooks
```
Requirements:
- Public HTTPS endpoint (webhooks need SSL)
- Ability to handle concurrent requests
- Quick response time (acknowledge within 3 seconds)

Options:
- AWS Lambda + API Gateway (serverless)
- Digital Ocean droplet with nginx
- Heroku app
- Azure Functions
```

### B. Database for Tracking
```sql
Essential Tables:
- compliance_tracking (enrollments, deadlines, status)
- users (employee info, manager relationships)
- sms_queue (pending messages)
- sms_log (sent messages, delivery status)
- escalation_rules (who to notify when)
```

### C. SMS Service
```
Options:
1. Twilio (most popular)
   - ~$0.0075 per SMS
   - Good API, delivery reports
   
2. AWS SNS (if already on AWS)
   - ~$0.00645 per SMS
   - Integrates with Lambda
   
3. Vonage/Nexmo
   - Similar pricing
   - Good international coverage
```

### D. Background Job Processor
```
For scheduled checks and retries:
- Cron jobs (simple)
- AWS EventBridge (serverless)
- Celery (Python)
- Sidekiq (Ruby)
```

## 3. Data Flow Architecture

```
┌─────────────────┐
│  Adobe Learning │
│    Manager      │
└────────┬────────┘
         │ Webhooks
         ▼
┌─────────────────┐     ┌─────────────────┐
│  Your Webhook   │────▶│   Database      │
│   Endpoint      │     │ (MySQL/Postgres)│
└────────┬────────┘     └────────┬────────┘
         │                       │
         ▼                       ▼
┌─────────────────┐     ┌─────────────────┐
│  Message Queue  │     │  Scheduled Job  │
│  (Redis/SQS)    │     │  (Cron/Lambda)  │
└────────┬────────┘     └────────┬────────┘
         │                       │
         └───────────┬───────────┘
                     ▼
            ┌─────────────────┐
            │   SMS Service   │
            │ (Twilio/AWS SNS)│
            └────────┬────────┘
                     ▼
              Manager's Phone
```

## 4. Key Data to Track

### From Webhooks:
```json
{
  "userId": "emp123",
  "courseId": "safety-2024",
  "eventName": "COURSE_ENROLLMENT_BATCH",
  "eventTime": 1703001600000,
  "enrollmentDate": "2024-01-15",
  "dueDate": "2024-02-15"  // May need to calculate
}
```

### In Your Database:
```sql
- Enrollment date/time
- Calculated deadline (enrollment + X days)
- Current progress percentage
- Manager contact info
- Escalation status
- Alert history
```

## 5. Implementation Steps

### Step 1: Set Up Basic Infrastructure
```bash
# 1. Create server endpoint
# 2. Set up database
# 3. Configure SMS service
# 4. Test webhook reception
```

### Step 2: Configure ALM Webhooks
```
1. Log in as Integration Admin
2. Go to Webhooks > Add Webhook
3. Configure:
   - URL: https://your-server.com/webhooks/alm
   - Events: Select enrollment, progress, completion
   - Authentication: Signature (recommended)
```

### Step 3: Build Core Logic
```php
// Pseudo-code for main handler
class ComplianceWebhookHandler {
    function handle($event) {
        switch($event->type) {
            case 'ENROLLMENT':
                $this->createDeadline($event);
                $this->scheduleReminders($event);
                break;
            case 'PROGRESS':
                $this->updateProgress($event);
                $this->checkIfOnTrack($event);
                break;
            case 'COMPLETION':
                $this->markComplete($event);
                $this->cancelAlerts($event);
                break;
        }
    }
}
```

### Step 4: SMS Alert Logic
```php
// When to send SMS
$alertSchedule = [
    '-7 days' => 'gentle_reminder',
    '-3 days' => 'urgent_reminder', 
    '-1 day'  => 'final_warning',
    '+1 day'  => 'missed_deadline',
    '+3 days' => 'escalation_1',
    '+7 days' => 'escalation_2'
];
```

## 6. Critical Features to Include

### A. Manager Hierarchy
```sql
-- Need to know who to notify
SELECT 
    e.id as employee_id,
    e.name as employee_name,
    m1.phone as direct_manager_phone,
    m2.phone as skip_manager_phone
FROM employees e
JOIN employees m1 ON e.manager_id = m1.id
LEFT JOIN employees m2 ON m1.manager_id = m2.id
```

### B. Do Not Disturb Rules
```php
// Don't send SMS at inappropriate times
function canSendSMS($phoneNumber) {
    $hour = date('H');
    $dayOfWeek = date('w');
    
    // No SMS between 9 PM and 8 AM
    if ($hour < 8 || $hour > 21) return false;
    
    // No SMS on weekends unless critical
    if (in_array($dayOfWeek, [0, 6]) && !$this->isCritical) {
        return false;
    }
    
    return true;
}
```

### C. Deduplication
```php
// Prevent duplicate SMS
function shouldSendAlert($userId, $courseId, $alertType) {
    $recentAlert = $this->db->query("
        SELECT * FROM sms_log 
        WHERE user_id = ? 
        AND course_id = ? 
        AND alert_type = ?
        AND sent_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ");
    
    return empty($recentAlert);
}
```

### D. Bulk Handling
```php
// Handle many overdue at once
function sendBulkAlerts() {
    $overdue = $this->getOverdueTrainings();
    
    // Group by manager to avoid spam
    $byManager = [];
    foreach ($overdue as $training) {
        $byManager[$training->managerId][] = $training;
    }
    
    // Send consolidated SMS
    foreach ($byManager as $managerId => $trainings) {
        if (count($trainings) > 3) {
            $this->sendSummarySMS($managerId, $trainings);
        } else {
            $this->sendIndividualSMS($managerId, $trainings);
        }
    }
}
```

## 7. Cost Estimation

### For 1000 employees:
```
- 10% miss deadlines = 100 employees
- 3 SMS per incident = 300 SMS
- Cost: 300 × $0.0075 = $2.25/month
- Infrastructure: ~$50-100/month
- Total: < $150/month

ROI: One prevented compliance violation pays for years of operation
```

## 8. Testing Strategy

### Test Scenarios:
1. **Happy Path**: Enrollment → Progress → Completion
2. **Warning Path**: Enrollment → Slow progress → Warnings → Completion
3. **Escalation Path**: Enrollment → No progress → Escalation chain
4. **Edge Cases**: 
   - Multiple enrollments same day
   - Manager changes mid-training
   - Course removed/modified

### Load Testing:
```bash
# Simulate bulk enrollment
for i in {1..100}; do
    curl -X POST https://your-webhook-endpoint.com \
        -d '{"eventName":"COURSE_ENROLLMENT_BATCH","userId":"emp'$i'"}'
done
```

## 9. Monitoring & Maintenance

### Key Metrics:
- Webhook delivery success rate
- SMS delivery rate
- Alert-to-completion conversion
- Average time to compliance
- Cost per alert

### Alerts to Set Up:
- Webhook endpoint down
- SMS quota exceeded  
- Database connection issues
- High failure rate

## 10. Security Considerations

### Webhook Security:
```php
// Verify webhook signature
function verifyWebhook($payload, $signature) {
    $expected = hash_hmac('sha256', $payload, $this->secret);
    return hash_equals($expected, $signature);
}
```

### Data Privacy:
- Encrypt phone numbers at rest
- Log minimal PII
- Regular audit trails
- GDPR compliance if applicable

## Quick Start Checklist

- [ ] ALM Integration Admin access
- [ ] Server with HTTPS endpoint
- [ ] Database (MySQL/PostgreSQL)
- [ ] SMS service account (Twilio/SNS)
- [ ] Manager hierarchy data
- [ ] Compliance deadline rules
- [ ] Test phone numbers
- [ ] Monitoring setup
- [ ] Budget approval
- [ ] Privacy/legal review

With these components in place, you can build a robust compliance tracking system that automatically alerts managers before their team members miss critical training deadlines!