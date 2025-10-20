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

### Code Examples (in `/src/`)
- **[Basic Receiver](src/webhook-receiver.php)** - Simple PHP webhook endpoint
- **[Secure Receiver](src/webhook-signature-validator.php)** - Production-ready with signature validation
- **[Production Webhook](src/webhook-alm.php)** - Full-featured compliance tracking with SQLite database
- **[Compliance Dashboard](src/dashboard.php)** - Real-time monitoring dashboard with auto-refresh

## 🚀 Quick Start

### Option 1: Production Deployment (Recommended)

1. **Install Dependencies**
   ```bash
   sudo apt-get install php php-sqlite3
   ```

2. **Deploy Files**
   ```bash
   # Copy src/ to your web server
   cp -r src/ /var/www/alm-webhooks/

   # Set permissions
   chown -R www-data:www-data /var/www/alm-webhooks/
   mkdir -p /var/www/alm-webhooks/{data,logs}
   chmod 775 /var/www/alm-webhooks/{data,logs}
   ```

3. **Configure in ALM**
   - Login as Integration Administrator
   - Navigate to Webhooks → Add Webhook
   - Enter your webhook URL: `https://yourdomain.com/path/to/webhook-alm.php`
   - Select events: COURSE_ENROLLMENT_BATCH, LEARNER_PROGRESS, COURSE_COMPLETION
   - Choose Signature authentication (recommended)

4. **Monitor via Dashboard**
   - Access dashboard at: `https://yourdomain.com/path/to/dashboard.php`
   - View real-time compliance tracking
   - Monitor webhook events and activity logs
   - Auto-refreshes every 30 seconds

### Option 2: Local Testing

1. **Test Locally**
   ```bash
   # Start PHP server
   cd src/
   php -S localhost:8000 webhook-receiver.php

   # Expose with ngrok
   ngrok http 8000
   ```

2. **Verify Setup**
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

## 🚀 Production Deployment

**Live Demo Instance:**
- Webhook: `https://p0qp0q.com/AdobeLearningManager_Webhook_Demo/src/webhook-alm.php`
- Dashboard: `https://p0qp0q.com/AdobeLearningManager_Webhook_Demo/src/dashboard.php`
- Deployed: October 2025
- Status: Active and receiving events from Adobe Learning Manager

**Stack:**
- PHP 8.3 with SQLite3
- Apache 2.4 on Ubuntu 24.04
- SSL/HTTPS via Let's Encrypt

---

**Note**: This is an unofficial guide created for educational purposes. For official support, please contact Adobe.