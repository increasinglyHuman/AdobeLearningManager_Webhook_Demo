# Tracking Completions & Progress with ALM Webhooks

## Overview
Webhooks excel at real-time progress and completion tracking because they push updates instantly when learning events occur, eliminating the need for constant API polling.

## Key Events for Tracking

### 1. **LEARNER_PROGRESS**
- Fires when a learner completes a module within a course
- Real-time event (immediate notification)
- Includes progress percentage

### 2. **COURSE_COMPLETION** / **LEARNER_COMPLETION**
- Fires when entire course/learning path is completed
- Can be triggered by learner action OR admin marking
- Includes completion date and score (if applicable)

### 3. **COURSE_ENROLLMENT_BATCH**
- Fires when learners are enrolled
- Good for initializing progress tracking
- Batch event (may have slight delay)

## Real-World Implementation Examples

### Example 1: Real-Time Progress Dashboard
```php
// webhook-progress-handler.php
<?php
$data = json_decode(file_get_contents('php://input'), true);

if ($data['eventName'] === 'LEARNER_PROGRESS') {
    $progress = [
        'userId' => $data['userId'],
        'courseId' => $data['learningObjectId'],
        'progress' => $data['progress'],
        'moduleCompleted' => $data['moduleId'],
        'timestamp' => $data['eventTime']
    ];
    
    // Update real-time dashboard via WebSocket
    $websocket->broadcast('progress.update', $progress);
    
    // Store in time-series database for analytics
    $influxDB->writePoints([
        new Point(
            'learner_progress',
            $progress['progress'],
            ['user' => $userId, 'course' => $courseId],
            $timestamp
        )
    ]);
}
```

### Example 2: Completion Certificate Automation
```php
// webhook-completion-handler.php
<?php
if ($data['eventName'] === 'COURSE_COMPLETION') {
    $completion = [
        'userId' => $data['userId'],
        'courseId' => $data['learningObjectId'],
        'completedAt' => $data['dateCompleted'],
        'score' => $data['score'] ?? null
    ];
    
    // Trigger certificate generation
    $certificateService->generate([
        'template' => 'course_completion',
        'user' => getUserDetails($completion['userId']),
        'course' => getCourseDetails($completion['courseId']),
        'date' => $completion['completedAt'],
        'score' => $completion['score']
    ]);
    
    // Send congratulations email
    $mailer->send('completion_congratulations', [
        'to' => getUserEmail($completion['userId']),
        'courseName' => getCourseName($completion['courseId'])
    ]);
    
    // Update CRM
    $salesforce->updateContact($userId, [
        'Training_Status__c' => 'Completed',
        'Last_Training_Date__c' => $completion['completedAt']
    ]);
}
```

### Example 3: Manager Alert System
```php
// webhook-manager-alerts.php
<?php
class ManagerAlertHandler {
    public function handleProgress($data) {
        $progress = $data['progress'];
        $userId = $data['userId'];
        
        // Alert manager if progress is concerning
        if ($this->isStalled($userId, $progress)) {
            $manager = $this->getManager($userId);
            
            $this->notify($manager, [
                'type' => 'progress_stalled',
                'employee' => $userId,
                'course' => $data['learningObjectId'],
                'currentProgress' => $progress,
                'lastActivity' => $data['eventTime']
            ]);
        }
        
        // Celebrate milestones
        if ($progress >= 90 && $this->isFirstTime90Percent($userId)) {
            $this->notify($manager, [
                'type' => 'almost_complete',
                'employee' => $userId,
                'course' => $data['learningObjectId']
            ]);
        }
    }
    
    private function isStalled($userId, $progress) {
        // Check if progress hasn't moved in 2 weeks
        $lastProgress = $this->db->getLastProgress($userId);
        $daysSinceLastProgress = $this->daysSince($lastProgress['date']);
        
        return $daysSinceLastProgress > 14 && $progress < 100;
    }
}
```

### Example 4: Gamification Points System
```php
// webhook-gamification.php
<?php
class GamificationEngine {
    private $pointRules = [
        'module_complete' => 10,
        'course_complete' => 100,
        'perfect_score' => 50,
        'fast_completion' => 25,
        'streak_bonus' => 15
    ];
    
    public function processProgress($webhook) {
        $points = 0;
        $badges = [];
        
        if ($webhook['eventName'] === 'LEARNER_PROGRESS') {
            // Points for module completion
            $points += $this->pointRules['module_complete'];
            
            // Check for streaks
            if ($this->hasLearningStreak($webhook['userId'])) {
                $points += $this->pointRules['streak_bonus'];
                $badges[] = 'streak_warrior';
            }
        }
        
        if ($webhook['eventName'] === 'COURSE_COMPLETION') {
            $points += $this->pointRules['course_complete'];
            
            // Bonus for perfect score
            if ($webhook['score'] == 100) {
                $points += $this->pointRules['perfect_score'];
                $badges[] = 'perfectionist';
            }
            
            // Bonus for fast completion
            if ($this->isCompletedQuickly($webhook)) {
                $points += $this->pointRules['fast_completion'];
                $badges[] = 'speed_learner';
            }
        }
        
        // Update leaderboard
        $this->updateLeaderboard($webhook['userId'], $points);
        $this->awardBadges($webhook['userId'], $badges);
        
        // Notify user of achievements
        $this->pushNotification($webhook['userId'], [
            'points' => $points,
            'badges' => $badges,
            'newTotal' => $this->getTotalPoints($webhook['userId'])
        ]);
    }
}
```

### Example 5: Compliance Tracking Dashboard
```php
// webhook-compliance-tracker.php
<?php
class ComplianceTracker {
    public function handleWebhook($data) {
        switch($data['eventName']) {
            case 'COURSE_ENROLLMENT_BATCH':
                $this->initializeCompliance($data);
                break;
                
            case 'LEARNER_PROGRESS':
                $this->updateComplianceProgress($data);
                break;
                
            case 'COURSE_COMPLETION':
                $this->markCompliant($data);
                break;
        }
    }
    
    private function updateComplianceProgress($data) {
        $compliance = [
            'userId' => $data['userId'],
            'courseId' => $data['learningObjectId'],
            'progress' => $data['progress'],
            'dueDate' => $this->getComplianceDueDate($data['userId'], $data['learningObjectId'])
        ];
        
        // Calculate risk level
        $daysUntilDue = $this->daysUntil($compliance['dueDate']);
        $expectedProgress = $this->getExpectedProgress($daysUntilDue);
        
        if ($compliance['progress'] < $expectedProgress) {
            $riskLevel = 'high';
            
            // Send escalation
            $this->escalateToManagement([
                'user' => $compliance['userId'],
                'course' => $compliance['courseId'],
                'currentProgress' => $compliance['progress'],
                'expectedProgress' => $expectedProgress,
                'daysRemaining' => $daysUntilDue
            ]);
        }
        
        // Update compliance dashboard
        $this->updateDashboard($compliance);
    }
}
```

## Integration Patterns

### Pattern 1: Event Stream Processing
```javascript
// Use webhooks to feed real-time analytics
ALM Webhook → AWS Kinesis → Analytics Dashboard
              ↓
            S3 Archive → Historical Analysis
```

### Pattern 2: Multi-Channel Notifications
```javascript
// One webhook, multiple actions
ALM Webhook → Lambda Function → Slack (team notification)
                              → Email (personal notification)
                              → SMS (urgent reminders)
                              → Database (record keeping)
```

### Pattern 3: Progressive Automation
```javascript
// Different actions based on progress thresholds
Progress 25% → Send encouragement
Progress 50% → Notify manager of good progress  
Progress 75% → Prepare certificate template
Progress 90% → Alert about almost complete
Progress 100% → Generate certificate, update systems
```

## Best Practices

### 1. **Idempotency**
```php
// Always check if you've already processed this event
$eventId = $webhook['eventId'];
if ($this->alreadyProcessed($eventId)) {
    return; // Prevent duplicate processing
}
$this->markAsProcessed($eventId);
```

### 2. **Async Processing**
```php
// Don't block the webhook response
http_response_code(200);
echo json_encode(['status' => 'received']);
fastcgi_finish_request(); // Send response immediately

// Now do the heavy lifting
$this->processWebhookAsync($data);
```

### 3. **Error Handling**
```php
try {
    $this->processProgress($webhook);
} catch (Exception $e) {
    // Log error but still return 200 to prevent retries
    error_log("Webhook processing failed: " . $e->getMessage());
    // Queue for manual review
    $this->queueForRetry($webhook, $e);
}
```

### 4. **Progress State Machine**
```php
class ProgressStateMachine {
    const STATES = [
        'not_started' => ['enrolled'],
        'enrolled' => ['in_progress', 'dropped'],
        'in_progress' => ['completed', 'stalled', 'dropped'],
        'stalled' => ['in_progress', 'dropped'],
        'completed' => [], // Terminal state
        'dropped' => [] // Terminal state
    ];
    
    public function transition($currentState, $event, $data) {
        // Validate state transitions
        // Trigger appropriate actions
        // Maintain audit trail
    }
}
```

## Common Challenges & Solutions

### Challenge 1: Out-of-Order Events
**Problem**: Completion webhook arrives before progress webhooks
**Solution**: Implement event ordering logic
```php
if ($event['type'] === 'completion' && !$this->hasProgressEvents($userId)) {
    $this->queueForLaterProcessing($event, '+5 minutes');
}
```

### Challenge 2: Missing Events
**Problem**: Network issues cause dropped webhooks
**Solution**: Periodic reconciliation with API
```php
// Daily job to catch missed events
$this->reconcileProgressViaAPI($yesterday = date('Y-m-d', strtotime('-1 day')));
```

### Challenge 3: Scale
**Problem**: Thousands of progress events per second
**Solution**: Event streaming architecture
```php
// Use message queue to buffer events
$webhook → Redis Pub/Sub → Multiple Workers → Database
```

The key advantage of webhooks for progress tracking is **immediacy** - you know the moment something happens, enabling real-time dashboards, instant notifications, and timely interventions.