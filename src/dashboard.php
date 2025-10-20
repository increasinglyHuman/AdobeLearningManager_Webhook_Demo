<?php
/**
 * ALM Compliance Tracking Dashboard
 * Displays webhook events and compliance status
 */

$db = new SQLite3(__DIR__ . '/../data/compliance.db');
?>
<!DOCTYPE html>
<html>
<head>
    <title>ALM Compliance Tracking Dashboard</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 1400px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        h1 { color: #333; border-bottom: 3px solid #0066cc; padding-bottom: 10px; }
        h2 { color: #555; margin-top: 30px; }
        table { border-collapse: collapse; width: 100%; margin: 20px 0; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; font-size: 14px; }
        th { background: #0066cc; color: white; font-weight: bold; }
        .overdue { background: #ffdddd; }
        .completed { background: #ddffdd; }
        .pending { background: #ffffdd; }
        .log { background: #f9f9f9; padding: 15px; font-family: monospace;
               white-space: pre-wrap; max-height: 300px; overflow-y: auto;
               border: 1px solid #ddd; border-radius: 4px; }
        .stats { display: flex; gap: 20px; margin: 20px 0; }
        .stat-box { flex: 1; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                    color: white; padding: 20px; border-radius: 8px; text-align: center; }
        .stat-box h3 { margin: 0; font-size: 36px; }
        .stat-box p { margin: 5px 0 0 0; font-size: 14px; opacity: 0.9; }
        .refresh-info { text-align: center; color: #666; font-size: 12px; margin-top: 20px; }
    </style>
    <meta http-equiv="refresh" content="30">
</head>
<body>
    <div class="container">
        <h1>🎓 ALM Compliance Tracking Dashboard</h1>

        <?php
        // Get statistics
        $total = $db->querySingle('SELECT COUNT(*) FROM compliance_tracking');
        $completed = $db->querySingle('SELECT COUNT(*) FROM compliance_tracking WHERE status = "completed"');
        $overdue = $db->querySingle('SELECT COUNT(*) FROM compliance_tracking WHERE
            CAST((julianday(deadline_date) - julianday("now")) AS INTEGER) < 0 AND status != "completed"');
        $events = $db->querySingle('SELECT COUNT(*) FROM webhook_events');
        ?>

        <div class="stats">
            <div class="stat-box">
                <h3><?php echo $total; ?></h3>
                <p>Total Enrollments</p>
            </div>
            <div class="stat-box" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                <h3><?php echo $completed; ?></h3>
                <p>Completed</p>
            </div>
            <div class="stat-box" style="background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);">
                <h3><?php echo $overdue; ?></h3>
                <p>Overdue</p>
            </div>
            <div class="stat-box" style="background: linear-gradient(135deg, #30cfd0 0%, #330867 100%);">
                <h3><?php echo $events; ?></h3>
                <p>Total Events</p>
            </div>
        </div>

        <h2>📊 Compliance Status</h2>
        <table>
            <tr>
                <th>User ID</th>
                <th>Course ID</th>
                <th>Instance</th>
                <th>Status</th>
                <th>Progress</th>
                <th>Enrollment Date</th>
                <th>Deadline</th>
                <th>Days Left</th>
            </tr>
            <?php
            $stmt = $db->query('SELECT *,
                CAST((julianday(deadline_date) - julianday("now")) AS INTEGER) as days_left
                FROM compliance_tracking ORDER BY deadline_date DESC LIMIT 50');

            while ($row = $stmt->fetchArray(SQLITE3_ASSOC)) {
                $class = '';
                if ($row['status'] == 'completed') $class = 'completed';
                elseif ($row['days_left'] < 0 && $row['status'] != 'completed') $class = 'overdue';
                elseif ($row['days_left'] < 3) $class = 'pending';

                echo "<tr class='$class'>";
                echo "<td>" . htmlspecialchars($row['user_id']) . "</td>";
                echo "<td>" . htmlspecialchars($row['course_id']) . "</td>";
                echo "<td>" . htmlspecialchars($row['instance_id'] ?? 'N/A') . "</td>";
                echo "<td>" . htmlspecialchars($row['status']) . "</td>";
                echo "<td>{$row['progress']}%</td>";
                echo "<td>" . htmlspecialchars($row['enrollment_date'] ?? 'N/A') . "</td>";
                echo "<td>" . htmlspecialchars($row['deadline_date'] ?? 'N/A') . "</td>";
                echo "<td>{$row['days_left']}</td>";
                echo "</tr>";
            }

            if ($total == 0) {
                echo "<tr><td colspan='8' style='text-align: center; padding: 20px; color: #999;'>No enrollment data yet. Waiting for webhook events...</td></tr>";
            }
            ?>
        </table>

        <h2>📡 Recent Webhook Events</h2>
        <table>
            <tr>
                <th>Event ID</th>
                <th>Event Name</th>
                <th>Account ID</th>
                <th>Timestamp</th>
            </tr>
            <?php
            $stmt = $db->query('SELECT * FROM webhook_events ORDER BY created_at DESC LIMIT 20');
            $hasEvents = false;
            while ($row = $stmt->fetchArray(SQLITE3_ASSOC)) {
                $hasEvents = true;
                echo "<tr>";
                echo "<td>" . htmlspecialchars($row['event_id']) . "</td>";
                echo "<td>" . htmlspecialchars($row['event_name']) . "</td>";
                echo "<td>" . htmlspecialchars($row['account_id']) . "</td>";
                echo "<td>" . htmlspecialchars($row['created_at']) . "</td>";
                echo "</tr>";
            }

            if (!$hasEvents) {
                echo "<tr><td colspan='4' style='text-align: center; padding: 20px; color: #999;'>No webhook events received yet...</td></tr>";
            }
            ?>
        </table>

        <h2>📝 Recent Webhook Activity Log</h2>
        <div class="log">
<?php
$logFile = __DIR__ . '/../logs/webhook-raw.log';
if (file_exists($logFile)) {
    $logs = file_get_contents($logFile);
    echo htmlspecialchars($logs ?: 'No activity logged yet');
} else {
    echo 'Log file not found';
}
?>
        </div>

        <div class="refresh-info">
            <p>⏱️ Page auto-refreshes every 30 seconds | Last updated: <?php echo date('Y-m-d H:i:s'); ?></p>
            <p>📍 Webhook URL: https://p0qp0q.com/AdobeLearningManager_Webhook_Demo/src/webhook-alm.php</p>
        </div>
    </div>
</body>
</html>
