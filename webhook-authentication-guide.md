# ALM Webhook Authentication Options

## Three Authentication Methods

### 1. **None (No Authentication)**
```php
// Your endpoint receives data directly
// No verification - anyone could send data to your endpoint
$data = json_decode(file_get_contents('php://input'), true);
// ⚠️ RISK: Easy to spoof
```

**When to use:**
- Development/testing only
- Internal network endpoints
- Non-sensitive data

**Security Risk:** ⚠️ **HIGH**
- Anyone can POST fake events to your endpoint
- No way to verify data came from ALM

---

### 2. **Basic Authentication**
```php
// ALM sends credentials in Authorization header
// Authorization: Basic base64(username:password)

$headers = getallheaders();
$auth = $headers['Authorization'] ?? '';

if ($auth !== 'Basic ' . base64_encode('myuser:mypass')) {
    http_response_code(401);
    exit('Unauthorized');
}
```

**How it works:**
1. You provide username/password when configuring webhook
2. ALM includes credentials in every request
3. Your endpoint validates credentials

**When to use:**
- Simple integrations
- Internal tools
- When HTTPS is guaranteed

**Security Risk:** ⚠️ **MEDIUM**
- Credentials sent with every request
- Safe over HTTPS, dangerous over HTTP
- Shared secret = shared risk

---

### 3. **Signature Authentication**
```php
// ALM signs the payload with a shared secret
// Signature sent in header (likely X-ALM-Signature)

function validateSignature($payload, $signature, $secret) {
    $expected = hash_hmac('sha256', $payload, $secret);
    return hash_equals($expected, $signature);
}

$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_ALM_SIGNATURE'] ?? '';

if (!validateSignature($payload, $signature, $webhook_secret)) {
    http_response_code(401);
    exit('Invalid signature');
}
```

**How it works:**
1. You create a secret key when configuring webhook
2. ALM creates HMAC signature of payload using secret
3. Your endpoint recalculates signature and compares
4. Only someone with the secret can create valid signature

**When to use:**
- Production environments
- Sensitive data
- Public endpoints
- Best practice for all webhooks

**Security Risk:** ✅ **LOW**
- Secret never transmitted
- Each payload uniquely signed
- Replay attacks prevented with timestamps

---

## Security Comparison

| Method | Security | Complexity | Use Case |
|--------|----------|------------|----------|
| None | ❌ Poor | ⭐ Simple | Dev only |
| Basic | ⚠️ Fair | ⭐⭐ Medium | Internal |
| Signature | ✅ Good | ⭐⭐⭐ Complex | Production |

## Implementation Examples

### Basic Auth Setup
```php
// .env file
WEBHOOK_USER=alm_webhook
WEBHOOK_PASS=SuperSecret123!

// webhook endpoint
$valid_user = $_ENV['WEBHOOK_USER'];
$valid_pass = $_ENV['WEBHOOK_PASS'];

$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
list($type, $credentials) = explode(' ', $auth, 2);

if ($type !== 'Basic') {
    exit('Invalid auth type');
}

list($user, $pass) = explode(':', base64_decode($credentials), 2);

if ($user !== $valid_user || $pass !== $valid_pass) {
    http_response_code(401);
    exit('Invalid credentials');
}
```

### Signature Validation (Production-Ready)
```php
class WebhookAuthenticator {
    private $secret;
    
    public function __construct($secret) {
        $this->secret = $secret;
    }
    
    public function isValid($payload, $signature) {
        // Remove any prefix (e.g., "sha256=")
        if (strpos($signature, '=') !== false) {
            list($algo, $hash) = explode('=', $signature, 2);
        } else {
            $algo = 'sha256';
            $hash = $signature;
        }
        
        // Calculate expected signature
        $expected = hash_hmac($algo, $payload, $this->secret);
        
        // Timing-safe comparison
        return hash_equals($expected, $hash);
    }
    
    public function middleware() {
        $payload = file_get_contents('php://input');
        $headers = getallheaders();
        
        // Check multiple possible header names
        $signature = $headers['X-ALM-Signature'] 
                  ?? $headers['X-Webhook-Signature'] 
                  ?? $headers['X-Hub-Signature-256'] 
                  ?? '';
        
        if (!$this->isValid($payload, $signature)) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Invalid signature']);
            exit;
        }
        
        // Return parsed payload for further processing
        return json_decode($payload, true);
    }
}

// Usage
$auth = new WebhookAuthenticator($_ENV['WEBHOOK_SECRET']);
$data = $auth->middleware();
// Process $data safely - it's verified!
```

## Best Practices

### 1. **Always Use HTTPS**
- Even with signature auth
- Prevents replay attacks
- Protects data in transit

### 2. **Rotate Secrets Regularly**
- Monthly for high-security environments
- Quarterly for standard use
- Keep old secret active during transition

### 3. **Log Authentication Failures**
```php
if (!$valid) {
    error_log("Webhook auth failed from IP: " . $_SERVER['REMOTE_ADDR']);
    // Consider rate limiting after X failures
}
```

### 4. **Implement Rate Limiting**
```php
$redis = new Redis();
$key = 'webhook_attempts:' . $_SERVER['REMOTE_ADDR'];
$attempts = $redis->incr($key);
$redis->expire($key, 3600); // 1 hour window

if ($attempts > 100) {
    http_response_code(429);
    exit('Rate limit exceeded');
}
```

### 5. **Verify Webhook Origin**
```php
// Additional security - verify source IP
$allowed_ips = ['52.x.x.x', '54.x.x.x']; // Adobe's IPs
if (!in_array($_SERVER['REMOTE_ADDR'], $allowed_ips)) {
    exit('Invalid source');
}
```

## Common Pitfalls

1. **Don't log the secret!**
```php
// BAD
error_log("Validating with secret: $secret");

// GOOD
error_log("Webhook validation " . ($valid ? 'passed' : 'failed'));
```

2. **Time-window validation**
```php
// Prevent replay attacks
$timestamp = $headers['X-Timestamp'] ?? 0;
if (abs(time() - $timestamp) > 300) { // 5 minutes
    exit('Request too old');
}
```

3. **Handle the challenge correctly**
```php
// ALM sends a challenge during setup
if (isset($data['challenge'])) {
    echo json_encode(['challenge' => $data['challenge']]);
    exit;
}
```