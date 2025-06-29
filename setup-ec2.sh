#!/bin/bash
# Quick setup script to run ON the EC2 instance

echo "🚀 Setting up ALM Webhook Demo on EC2"
echo "====================================="

# Update system
echo "📦 Installing required packages..."
sudo apt update
sudo apt install -y php php-cli php-sqlite3 php-curl sqlite3 nginx

# Create directory structure
echo "📁 Creating directories..."
sudo mkdir -p /var/www/alm-webhook-demo/{src,logs,data,config}

# Copy files from current directory to web root
echo "📄 Copying application files..."
sudo cp -r * /var/www/alm-webhook-demo/

# Set up permissions
echo "🔐 Setting permissions..."
sudo chown -R www-data:www-data /var/www/alm-webhook-demo
sudo chmod -R 755 /var/www/alm-webhook-demo
sudo chmod -R 777 /var/www/alm-webhook-demo/logs
sudo chmod -R 777 /var/www/alm-webhook-demo/data

# Configure nginx
echo "🌐 Configuring nginx..."
sudo tee /etc/nginx/sites-available/alm-webhook > /dev/null << 'EOF'
server {
    listen 80;
    server_name _;
    
    root /var/www/alm-webhook-demo/src;
    index dashboard.php index.php;
    
    location / {
        try_files $uri $uri/ =404;
    }
    
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
    }
    
    location /webhook {
        try_files $uri /webhook-demo.php?$query_string;
    }
    
    location ~ /\. {
        deny all;
    }
}
EOF

# Enable site
sudo ln -sf /etc/nginx/sites-available/alm-webhook /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl restart nginx

# Set up cron job for SMS processing
echo "⏰ Setting up cron job..."
(crontab -l 2>/dev/null; echo "*/5 * * * * cd /var/www/alm-webhook-demo && php src/process-sms.php >> logs/cron.log 2>&1") | crontab -

# Create test data directory
echo "💾 Initializing database..."
cd /var/www/alm-webhook-demo
sudo -u www-data php -r "new SQLite3('data/compliance.db');"

echo "✅ Setup complete!"
echo ""
echo "🎯 Next steps:"
echo "1. Your webhook endpoint is: http://$(curl -s ifconfig.me)/webhook"
echo "2. Dashboard is available at: http://$(curl -s ifconfig.me)/"
echo "3. Configure this webhook URL in Adobe Learning Manager"
echo ""
echo "📝 Test with:"
echo "curl -X POST http://localhost/webhook -H 'Content-Type: application/json' -d '{\"eventName\":\"COURSE_ENROLLMENT_BATCH\",\"userId\":\"test-001\",\"learningObjectId\":\"compliance-training\"}'"