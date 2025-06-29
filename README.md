# Adobe Learning Manager Webhooks Guide

A comprehensive collection of resources, code examples, and best practices for implementing webhooks with Adobe Learning Manager (ALM).

## 📚 What's Included

### Documentation
- **[Setup Guide](alm-webhooks-setup-guide.md)** - Step-by-step webhook configuration in ALM
- **[Authentication Guide](webhook-authentication-guide.md)** - Security options and implementation
- **[Use Cases](webhook-use-cases.md)** - 20 practical webhook applications
- **[Use Cases by Role](webhook-use-cases-by-role.md)** - Role-specific implementations
- **[Webhook Limits Analysis](webhook-limit-analysis.md)** - Understanding the 5-webhook limitation
- **[Scale Analysis](webhook-scale-analysis.md)** - Handling millions of learners

### Code Examples
- **[Basic Receiver](webhook-receiver.php)** - Simple PHP webhook endpoint
- **[Secure Receiver](webhook-signature-validator.php)** - Production-ready with signature validation

## 🚀 Quick Start

1. **Test Locally**
   ```bash
   # Start PHP server
   php -S localhost:8000 webhook-receiver.php
   
   # Expose with ngrok
   ngrok http 8000
   ```

2. **Configure in ALM**
   - Login as Integration Administrator
   - Navigate to Webhooks → Add Webhook
   - Enter your ngrok URL
   - Select events and authentication method

3. **Verify Setup**
   - ALM sends a challenge parameter
   - Your endpoint must echo it back
   - Check logs for incoming events

## 🔐 Security Recommendations

- **Always use HTTPS** in production
- **Choose Signature authentication** for best security
- **Validate all incoming data** before processing
- **Implement rate limiting** to prevent abuse
- **Log security events** for monitoring

## 📊 Supported Events

### Real-time Events
- `LEARNER_PROGRESS` - Module completion updates
- `CI_STATS` - Course instance statistics

### Batch Events (Admin-triggered)
- `COURSE_ENROLLMENT_BATCH` - Bulk enrollments
- `LEARNER_COMPLETION` - Completion marking
- `LEARNING_OBJECT_DRAFT` - Content creation
- `LEARNING_OBJECT_MODIFICATION` - Content updates

## 🏗️ Architecture Patterns

### Small Scale (<10K learners)
```
ALM → Your Webhook Endpoint → Your Systems
```

### Enterprise Scale (>100K learners)
```
ALM → Webhook Router → Message Queue → Multiple Consumers
```

## ⚠️ Limitations

- Maximum 5 webhooks per account
- Events retained for 7 days
- Not available for trial accounts
- Some events have processing delays

## 📈 Webhook Math

For large deployments:
```
1M learners × 10 events/day × 5 webhooks = 50M HTTP calls/day
```

Consider event streaming or batch exports for high-volume scenarios.

## 🤝 Contributing

Feel free to submit issues, fork the repository, and create pull requests for any improvements.

## 📄 License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## 🔗 Resources

- [Official ALM Webhooks Documentation](https://experienceleague.adobe.com/en/docs/learning-manager/using/integration/webhooks/webhooks)
- [ALM Developer Manual](https://experienceleague.adobe.com/en/docs/learning-manager/using/integration/developer-manual)
- [Adobe Learning Manager](https://business.adobe.com/products/learning-manager/adobe-learning-manager.html)

---

**Note**: This is an unofficial guide created for educational purposes. For official support, please contact Adobe.