# AWS Compliance SMS Solution - Detailed Pricing Breakdown

## Architecture Overview
```
ALM Webhooks → API Gateway → Lambda → DynamoDB → EventBridge → SNS → SMS
```

## Component-by-Component Pricing

### 1. **API Gateway** (Webhook Endpoint)
```
Pricing Model: Per request + data transfer
- First 1M requests/month: FREE
- Next 300M requests: $3.50 per million
- Data transfer: $0.09/GB out

Monthly estimate (10K employees, 100K events):
- 100K requests = FREE (under 1M)
- Data transfer (100KB/request): 10GB × $0.09 = $0.90
Total: ~$0.90/month
```

### 2. **AWS Lambda** (Process Webhooks)
```
Pricing Model: Requests + compute time
- First 1M requests/month: FREE
- First 400,000 GB-seconds: FREE
- After: $0.20 per 1M requests

Function specs: 512MB memory, 200ms average
- 100K invocations = FREE (under 1M)
- Compute: 100K × 0.5GB × 0.2s = 10,000 GB-s = FREE
Total: $0/month
```

### 3. **DynamoDB** (Database)
```
Pricing Model: On-demand or provisioned

On-Demand Pricing:
- Write: $1.25 per million writes
- Read: $0.25 per million reads
- Storage: $0.25 per GB/month

Monthly estimate:
- Writes: 100K = $0.125
- Reads: 500K = $0.125  
- Storage: 1GB = $0.25
Total: ~$0.50/month

Alternative - Provisioned (can be cheaper):
- 5 WCU + 25 RCU = ~$3.25/month
```

### 4. **EventBridge** (Scheduled Checks)
```
Pricing Model: Per rule + invocations
- Rules: First 100 rules FREE
- Events: $1.00 per million events

Monthly estimate:
- 10 rules (different check schedules) = FREE
- 50K scheduled checks = $0.05
Total: ~$0.05/month
```

### 5. **SNS for SMS** (Text Messages)
```
Pricing Model: Per SMS sent
USA Pricing:
- Transactional SMS: $0.00645 per SMS
- Promotional SMS: $0.00355 per SMS

Monthly estimate (300 SMS):
- 300 × $0.00645 = $1.94
Total: ~$1.94/month

International rates vary:
- Canada: $0.00651
- UK: $0.0394
- India: $0.00220
```

### 6. **CloudWatch Logs** (Monitoring)
```
Pricing Model: Ingestion + storage
- Ingestion: $0.50 per GB
- Storage: $0.03 per GB/month

Monthly estimate:
- 1GB logs = $0.50 + $0.03 = $0.53
Total: ~$0.53/month
```

## Total Monthly Cost Breakdown

### Basic Setup (1,000 employees):
```
API Gateway:        $0.90
Lambda:             $0.00 (free tier)
DynamoDB:           $0.50
EventBridge:        $0.05
SNS (300 SMS):      $1.94
CloudWatch:         $0.53
─────────────────────────
Total:              $3.92/month
```

### Scale Comparison:
```
1K employees:    ~$4/month
10K employees:   ~$25/month  
100K employees:  ~$200/month
```

## Complete Serverless Solution Code

### 1. Infrastructure as Code (AWS CDK)
```python
# cdk_stack.py
from aws_cdk import (
    Stack,
    aws_lambda as lambda_,
    aws_apigateway as apigw,
    aws_dynamodb as dynamodb,
    aws_events as events,
    aws_sns as sns,
    aws_iam as iam,
    Duration
)

class ComplianceTrackingStack(Stack):
    def __init__(self, scope, id, **kwargs):
        super().__init__(scope, id, **kwargs)
        
        # DynamoDB Table
        compliance_table = dynamodb.Table(
            self, "ComplianceTracking",
            partition_key=dynamodb.Attribute(
                name="userId",
                type=dynamodb.AttributeType.STRING
            ),
            sort_key=dynamodb.Attribute(
                name="courseId",
                type=dynamodb.AttributeType.STRING
            ),
            billing_mode=dynamodb.BillingMode.PAY_PER_REQUEST
        )
        
        # Lambda Function
        webhook_handler = lambda_.Function(
            self, "WebhookHandler",
            runtime=lambda_.Runtime.PYTHON_3_9,
            code=lambda_.Code.from_asset("lambda"),
            handler="webhook_handler.handler",
            memory_size=512,
            timeout=Duration.seconds(30),
            environment={
                "TABLE_NAME": compliance_table.table_name,
                "SNS_TOPIC_ARN": sns_topic.topic_arn
            }
        )
        
        # API Gateway
        api = apigw.RestApi(
            self, "ComplianceWebhookAPI",
            rest_api_name="Compliance Tracking Webhooks"
        )
        
        webhook_resource = api.root.add_resource("webhook")
        webhook_resource.add_method(
            "POST",
            apigw.LambdaIntegration(webhook_handler)
        )
        
        # EventBridge Rules
        deadline_checker = events.Rule(
            self, "DeadlineChecker",
            schedule=events.Schedule.rate(Duration.hours(1))
        )
        deadline_checker.add_target(
            targets.LambdaFunction(deadline_check_function)
        )
```

### 2. Lambda Webhook Handler
```python
# lambda/webhook_handler.py
import json
import boto3
import os
from datetime import datetime, timedelta

dynamodb = boto3.resource('dynamodb')
sns = boto3.client('sns')
table = dynamodb.Table(os.environ['TABLE_NAME'])

def handler(event, context):
    # Parse webhook
    body = json.loads(event['body'])
    
    # Quick response to ALM
    response = {
        'statusCode': 200,
        'body': json.dumps({'status': 'received'})
    }
    
    # Process based on event type
    if body['eventName'] == 'COURSE_ENROLLMENT_BATCH':
        handle_enrollment(body)
    elif body['eventName'] == 'LEARNER_PROGRESS':
        handle_progress(body)
    elif body['eventName'] == 'COURSE_COMPLETION':
        handle_completion(body)
    
    return response

def handle_enrollment(data):
    # Calculate deadline (30 days from enrollment)
    enrollment_date = datetime.now()
    deadline = enrollment_date + timedelta(days=30)
    
    # Store in DynamoDB
    table.put_item(
        Item={
            'userId': data['userId'],
            'courseId': data['learningObjectId'],
            'enrollmentDate': enrollment_date.isoformat(),
            'deadline': deadline.isoformat(),
            'status': 'enrolled',
            'progress': 0,
            'alertsSent': []
        }
    )

def check_deadlines():
    # Scan for upcoming/missed deadlines
    response = table.scan(
        FilterExpression=Attr('status').ne('completed')
    )
    
    for item in response['Items']:
        days_until_deadline = calculate_days_until(item['deadline'])
        
        if days_until_deadline == 7 and '7_day' not in item['alertsSent']:
            send_sms_alert(item, '7_day_warning')
        elif days_until_deadline == 3 and '3_day' not in item['alertsSent']:
            send_sms_alert(item, '3_day_warning')
        elif days_until_deadline == -1 and 'overdue' not in item['alertsSent']:
            send_sms_alert(item, 'overdue')

def send_sms_alert(training_record, alert_type):
    manager_phone = get_manager_phone(training_record['userId'])
    
    messages = {
        '7_day_warning': f"Reminder: {training_record['userId']} has 7 days to complete compliance training",
        '3_day_warning': f"URGENT: {training_record['userId']} has 3 days left for compliance training",
        'overdue': f"ALERT: {training_record['userId']} missed compliance deadline!"
    }
    
    # Send SMS via SNS
    sns.publish(
        PhoneNumber=manager_phone,
        Message=messages[alert_type],
        MessageAttributes={
            'AWS.SNS.SMS.SMSType': {
                'DataType': 'String',
                'StringValue': 'Transactional'
            }
        }
    )
    
    # Update record
    table.update_item(
        Key={'userId': training_record['userId'], 'courseId': training_record['courseId']},
        UpdateExpression='SET alertsSent = list_append(alertsSent, :alert)',
        ExpressionAttributeValues={':alert': [alert_type]}
    )
```

## Cost Optimization Tips

### 1. **Use Reserved Capacity**
```
DynamoDB Reserved Capacity:
- Save up to 77% with 1-year commitment
- Example: 5 WCU + 25 RCU
  - On-Demand: $3.25/month
  - Reserved: $0.75/month
```

### 2. **Batch Operations**
```python
# Instead of individual writes
with table.batch_writer() as batch:
    for item in items:
        batch.put_item(Item=item)
# Reduces write costs by 50%
```

### 3. **Smart SMS Batching**
```python
# Combine multiple alerts for same manager
def send_consolidated_sms(manager_id, overdue_employees):
    if len(overdue_employees) > 3:
        message = f"ALERT: {len(overdue_employees)} employees have overdue training. Check dashboard for details."
    else:
        names = ', '.join([e['name'] for e in overdue_employees])
        message = f"ALERT: {names} have missed compliance deadlines."
    
    # One SMS instead of many
    send_sms(manager_phone, message)
```

### 4. **Use SQS for Buffering**
```
Add SQS between Lambda and SNS:
- Prevents Lambda timeout issues
- Enables retry logic
- Costs: $0 (first 1M requests free)
```

## Regional Considerations

### SMS Pricing Varies by Region:
```
USA:        $0.00645/SMS
Singapore:  $0.0195/SMS  
Germany:    $0.0775/SMS
Brazil:     $0.0241/SMS

Tip: Route through cheapest region if possible
```

### Data Residency:
```
- DynamoDB Global Tables for multi-region
- Additional cost: ~$0.75 per million replicated writes
- Benefit: Local compliance + lower latency
```

## Free Tier Benefits (First 12 Months)

```
Service         Free Tier            Your Usage    Cost
─────────────────────────────────────────────────────────
API Gateway     1M requests/month    100K          $0
Lambda          1M requests/month    100K          $0
DynamoDB        25 GB storage        1 GB          $0
                25 WCU/RCU          Low usage      $0
CloudWatch      5 GB logs           1 GB          $0
─────────────────────────────────────────────────────────
                                    SMS only:      ~$2
```

## ROI Calculation

```
Costs:
- AWS Infrastructure: $4/month
- SMS costs: $2/month  
- Total: $6/month ($72/year)

Benefits:
- One prevented violation: $10,000+
- ROI: 13,888% 

Break-even: Preventing one violation every 138 years!
```

## Alternative: Hybrid Approach

For very cost-sensitive deployments:
```
Critical paths: AWS (reliability)
Bulk operations: On-premise cron jobs
Database: RDS instead of DynamoDB (~$15/month)
SMS: Bulk SMS provider (lower rates)
```

The AWS serverless approach gives you incredible scalability and reliability for less than the cost of a coffee subscription!