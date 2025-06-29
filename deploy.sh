#!/bin/bash
# Deploy script for ALM Webhook Demo

echo "🚀 ALM Webhook Demo Deployment Script"
echo "===================================="

# Configuration
REMOTE_USER="ubuntu"  # or ec2-user depending on your AMI
REMOTE_HOST="your-ec2-public-ip"  # Replace with your EC2 IP
REMOTE_DIR="/var/www/alm-webhook-demo"
KEY_PATH="./ssh/poqpoq2025.pem"

# Check if key exists
if [ ! -f "$KEY_PATH" ]; then
    echo "❌ Error: SSH key not found at $KEY_PATH"
    exit 1
fi

# Set proper permissions on key
chmod 600 "$KEY_PATH"

echo "📦 Preparing files for deployment..."

# Create deployment package
mkdir -p deploy-package
cp -r src/* deploy-package/
cp -r config/* deploy-package/ 2>/dev/null || true
cp -r docs/* deploy-package/ 2>/dev/null || true

echo "🔗 Connecting to EC2 instance..."

# Create remote directory
ssh -i "$KEY_PATH" "$REMOTE_USER@$REMOTE_HOST" "sudo mkdir -p $REMOTE_DIR && sudo chown -R $REMOTE_USER:www-data $REMOTE_DIR"

echo "📤 Uploading files..."

# Upload files
scp -i "$KEY_PATH" -r deploy-package/* "$REMOTE_USER@$REMOTE_HOST:$REMOTE_DIR/"

echo "🔧 Setting up on remote server..."

# Run setup commands on remote
ssh -i "$KEY_PATH" "$REMOTE_USER@$REMOTE_HOST" << 'ENDSSH'
    # Install dependencies if needed
    sudo apt update
    sudo apt install -y php php-sqlite3 nginx

    # Create necessary directories
    sudo mkdir -p /var/www/alm-webhook-demo/logs
    sudo mkdir -p /var/www/alm-webhook-demo/data
    
    # Set permissions
    sudo chown -R www-data:www-data /var/www/alm-webhook-demo
    sudo chmod -R 755 /var/www/alm-webhook-demo
    sudo chmod -R 777 /var/www/alm-webhook-demo/logs
    sudo chmod -R 777 /var/www/alm-webhook-demo/data
    
    # Configure nginx if needed
    if [ ! -f /etc/nginx/sites-available/alm-webhook ]; then
        echo "Setting up nginx configuration..."
        # nginx config will be set up here
    fi
    
    echo "✅ Deployment complete!"
ENDSSH

# Clean up
rm -rf deploy-package

echo "🎉 Deployment finished!"
echo "Visit http://$REMOTE_HOST to see the dashboard"