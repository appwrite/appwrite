<?php

/**
 * React Native OAuth2 Flow Demonstration
 * 
 * This script demonstrates how the React Native OAuth2 authentication
 * flow works with the implemented custom scheme support.
 */

echo "=== React Native OAuth2 Flow Demonstration ===\n\n";

// Simulate project configuration
$projectId = 'myreactnativeapp123';
echo "📱 Project ID: $projectId\n\n";

// Simulate the platforms that would be configured
$platforms = [
    // Web platforms for development and production
    [
        'type' => 'web',
        'hostname' => 'localhost',
        'name' => 'Development Web'
    ],
    [
        'type' => 'web', 
        'hostname' => 'myapp.com',
        'name' => 'Production Web'
    ],
    // React Native schemes (automatically added by the system)
    [
        'type' => 'scheme',
        'key' => 'exp',
        'name' => 'Expo Development'
    ],
    [
        'type' => 'scheme',
        'key' => "appwrite-callback-$projectId",
        'name' => 'React Native Production'
    ]
];

echo "🔧 Configured platforms:\n";
foreach ($platforms as $platform) {
    $identifier = $platform['hostname'] ?? $platform['key'];
    echo "   - {$platform['name']}: {$platform['type']}://$identifier\n";
}

echo "\n" . str_repeat("=", 60) . "\n\n";

// Demonstrate different OAuth2 scenarios
$scenarios = [
    [
        'name' => '📱 Expo Development',
        'description' => 'Developer testing OAuth2 in Expo Go app',
        'redirect_url' => 'exp://192.168.1.100:19000',
        'expected' => true
    ],
    [
        'name' => '🌐 Expo Hosted',
        'description' => 'Expo app published to exp.host',
        'redirect_url' => 'exp://exp.host/@developer/myapp',
        'expected' => true
    ],
    [
        'name' => '📱 React Native Production',
        'description' => 'Production React Native app with custom scheme',
        'redirect_url' => "appwrite-callback-$projectId://oauth/callback",
        'expected' => true
    ],
    [
        'name' => '💻 Web Development',
        'description' => 'Local web development',
        'redirect_url' => 'http://localhost:3000/auth/callback',
        'expected' => true
    ],
    [
        'name' => '🌐 Web Production',
        'description' => 'Production web application',
        'redirect_url' => 'https://myapp.com/oauth/success',
        'expected' => true
    ],
    [
        'name' => '❌ Invalid Scheme',
        'description' => 'Wrong custom scheme should be rejected',
        'redirect_url' => 'appwrite-callback-wrongproject://oauth',
        'expected' => false
    ],
    [
        'name' => '❌ Unauthorized Host',
        'description' => 'Unauthorized hostname should be rejected',
        'redirect_url' => 'https://malicious.com/steal-tokens',
        'expected' => false
    ]
];

foreach ($scenarios as $scenario) {
    echo $scenario['name'] . "\n";
    echo "Purpose: " . $scenario['description'] . "\n";
    echo "Redirect URL: " . $scenario['redirect_url'] . "\n";
    
    // Simple validation logic
    $url = $scenario['redirect_url'];
    $scheme = parse_url($url, PHP_URL_SCHEME);
    $host = parse_url($url, PHP_URL_HOST);
    
    $isValid = false;
    
    // Check if it's a web URL with allowed hostname
    if (in_array($scheme, ['http', 'https'])) {
        $allowedHosts = ['localhost', 'myapp.com'];
        $isValid = in_array($host, $allowedHosts);
    }
    // Check if it's an allowed custom scheme
    elseif (in_array($scheme, ['exp', "appwrite-callback-$projectId"])) {
        $isValid = true;
    }
    
    $status = $isValid ? "✅ ALLOWED" : "❌ REJECTED";
    $expectedStatus = $scenario['expected'] ? "✅ ALLOWED" : "❌ REJECTED";
    
    echo "Result: $status";
    if ($isValid === $scenario['expected']) {
        echo " (Expected: $expectedStatus) ✓\n";
    } else {
        echo " (Expected: $expectedStatus) ✗ MISMATCH!\n";
    }
    
    echo "\n";
}

echo str_repeat("=", 60) . "\n\n";

echo "🎉 OAuth2 Flow Benefits:\n";
echo "   ✅ Secure: Only pre-registered schemes and hostnames are allowed\n";
echo "   ✅ Flexible: Supports web, Expo, and React Native production apps\n";
echo "   ✅ Developer-friendly: Works seamlessly in development and production\n";
echo "   ✅ Standards-compliant: Follows OAuth2 security best practices\n\n";

echo "👨‍💻 Developer Experience:\n";
echo "   1. Add React Native platform in Appwrite console\n";
echo "   2. Use 'exp://' URLs for Expo development\n";
echo "   3. Use 'appwrite-callback-{projectId}://' for production builds\n";
echo "   4. OAuth2 redirects work seamlessly across all platforms\n\n";

echo "=== Demonstration Complete ===\n";