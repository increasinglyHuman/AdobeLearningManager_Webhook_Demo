# Why Only 5 Webhooks? Analysis of ALM's Limit

## Potential Technical Reasons

### 1. **Performance Protection**
- Each webhook = additional processing overhead
- Every event must be sent to every registered webhook
- With thousands of learners, this multiplies quickly
- Example: 10,000 learners × 10 events/day × 5 webhooks = 500,000 HTTP calls/day

### 2. **Reliability Concerns**
- Failed webhook deliveries need retry logic
- More webhooks = more retry queues to manage
- Dead endpoints can cause backlogs
- Protecting the core platform from webhook-induced slowdowns

### 3. **Multi-tenant Architecture**
- ALM likely serves many accounts on shared infrastructure
- Preventing "noisy neighbors" who might abuse webhooks
- Fair resource allocation across all customers

## Business/Strategic Reasons

### 4. **Encouraging Aggregation Patterns**
- Forces customers to build proper middleware
- One webhook → fan out to multiple systems
- Better architectural patterns vs. point-to-point integrations

### 5. **Support Overhead**
- Each webhook = potential support ticket
- Limiting webhooks limits troubleshooting complexity
- Easier to debug 5 endpoints than 50

### 6. **Upsell Opportunity**
- Premium tiers might offer more webhooks
- Enterprise contracts could negotiate higher limits
- Creates differentiation between plan levels

## Workarounds for the 5-Webhook Limit

### **Option 1: Webhook Aggregator Service**
```
ALM → Single Webhook → Your Aggregator → Multiple Systems
                          ├── Slack
                          ├── CRM
                          ├── HRIS
                          └── Analytics
```

### **Option 2: Event-Based Architecture**
```
ALM → Webhook → Message Queue (SQS/RabbitMQ) → Multiple Consumers
```

### **Option 3: Smart Routing**
```php
// Single webhook endpoint that routes to multiple handlers
switch($event['eventName']) {
    case 'ENROLLMENT':
        notifySlack($event);
        updateCRM($event);
        break;
    case 'COMPLETION':
        generateCertificate($event);
        updateHRIS($event);
        break;
}
```

## Comparison with Other Platforms

| Platform | Webhook Limit | Notes |
|----------|--------------|-------|
| GitHub | Unlimited* | Per repository |
| Stripe | 16 per account | Can request more |
| Shopify | Varies by plan | 4-40 webhooks |
| Salesforce | Based on edition | 10-100+ |
| **Adobe LM** | **5** | **Seems low** |

## Is This Extreme?

**YES**, compared to modern standards:
- Most SaaS platforms offer 10-20 minimum
- Developer-friendly platforms often unlimited
- 5 feels like a 2010-era limitation

**Possible explanations:**
- Legacy architecture constraints
- Conservative approach to new feature
- Planned increase in future releases
- Encourages enterprise middleware solutions

## Recommended Approach

1. **Start with 1-2 strategic webhooks**
   - One for real-time events
   - One for batch/admin events

2. **Build a routing layer**
   - Receive once, distribute many
   - Add filtering/transformation logic
   - Implement your own retry/queue

3. **Monitor and optimize**
   - Track which events you actually use
   - Combine related workflows
   - Consider polling for low-priority data

The 5-webhook limit essentially forces you to build a proper integration layer rather than creating webhook sprawl - frustrating but potentially leading to better architecture.