# Adobe Learning Manager Webhooks Setup Guide

## Overview
Webhooks in ALM enable real-time event notifications to external systems when specific actions occur in the platform.

## Key Limitations
- Maximum of 5 webhooks per account
- Not available for trial/inactive accounts
- Events retained for 7 days

## Setup Instructions

### Prerequisites
- Integration Administrator role in ALM
- A publicly accessible URL endpoint to receive webhook data

### Step-by-Step Setup

1. **Login as Integration Administrator**
   - Access your ALM account with Integration Admin privileges

2. **Navigate to Webhooks**
   - Go to the Webhooks section in the admin panel

3. **Create New Webhook**
   - Click "Add Webhook"
   - Fill in required fields:
     - **Name**: Descriptive name for your webhook
     - **Description**: Purpose of this webhook
     - **Target URL**: Your endpoint URL (must be HTTPS for production)
     - **Authentication Method**:
       - None: No authentication required
       - Basic: Username/password authentication
       - Signature: HMAC signature validation
     - **Trigger Events**: Select which events should trigger this webhook
     - **Activation Status**: Enable/disable the webhook

## Event Types

### Real-time Events
- Triggered immediately when action occurs
- Example: LEARNER_PROGRESS

### Non-real-time (Batch) Events
- Triggered by admin/manager actions
- Example: COURSE_ENROLLMENT_BATCH

## Testing Your Webhook

### Local Testing with ngrok
```bash
# Install ngrok
# Start your local webhook receiver
php -S localhost:8000 webhook-receiver.php

# In another terminal, expose it with ngrok
ngrok http 8000

# Use the ngrok URL in ALM webhook configuration
```

### Verify Webhook Setup
ALM sends a verification challenge when setting up a webhook. Your endpoint must echo back the challenge parameter.

## Sample Payload Structure
```json
{
  "accountId": 1010,
  "events": [
    {
      "eventId": "d5fb7071-10a9-46b2-9f9e-79dde346c052",
      "eventName": "COURSE_ENROLLMENT_BATCH",
      "eventInfo": "1238,1250",
      "eventTime": 1726033387000,
      "eventInitiator": "1173",
      "eventData": {
        // Event-specific data
      }
    }
  ]
}
```

## Best Practices
1. Respond quickly with 200 OK to acknowledge receipt
2. Process events asynchronously if needed
3. Use event IDs to prevent duplicate processing
4. Log all received events for debugging
5. Implement proper error handling and retry logic