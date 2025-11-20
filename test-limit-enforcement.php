<?php
/**
 * Simple test to verify limit enforcement logic
 * Run: php test-limit-enforcement.php
 */

// Simulate the Query class
class Query {
    const TYPE_LIMIT = 'limit';
    
    private $method;
    private $value;
    
    public function __construct($method, $value) {
        $this->method = $method;
        $this->value = $value;
    }
    
    public function getMethod() {
        return $this->method;
    }
    
    public function getValue() {
        return $this->value;
    }
    
    public function setValue($value) {
        $this->value = $value;
    }
    
    public static function limit($value) {
        return new self(self::TYPE_LIMIT, $value);
    }
}

// Constants from your fix
const APP_LIMIT_LIST_DEFAULT = 25;
const APP_LIMIT_LIST_MAX = 1000;

// The enforceLimits function (copied from your fix)
function enforceLimits(array &$queries): void
{
    $limitQuery = null;
    $limitIndex = null;
    
    // Find existing limit query
    foreach ($queries as $index => $query) {
        if ($query->getMethod() === Query::TYPE_LIMIT) {
            $limitQuery = $query;
            $limitIndex = $index;
            break;
        }
    }
    
    if ($limitQuery === null) {
        // No limit specified, add default to prevent loading all documents
        $queries[] = Query::limit(APP_LIMIT_LIST_DEFAULT);
    } else {
        // Limit specified, cap it to maximum to prevent UI freezes
        $requestedLimit = $limitQuery->getValue();
        
        if ($requestedLimit > APP_LIMIT_LIST_MAX) {
            // Log the capping for monitoring purposes
            echo "⚠️  Document limit capped from {$requestedLimit} to " . APP_LIMIT_LIST_MAX . " for collection to prevent UI freeze\n";
            
            // Replace with capped limit
            $queries[$limitIndex] = Query::limit(APP_LIMIT_LIST_MAX);
        }
    }
}

// Test cases
echo "=== Testing Limit Enforcement ===\n\n";

// Test 1: No limit specified
echo "Test 1: No limit specified\n";
$queries1 = [];
enforceLimits($queries1);
$limit1 = null;
foreach ($queries1 as $q) {
    if ($q->getMethod() === Query::TYPE_LIMIT) {
        $limit1 = $q->getValue();
    }
}
echo "Result: Limit = {$limit1}\n";
echo "Expected: " . APP_LIMIT_LIST_DEFAULT . "\n";
echo ($limit1 === APP_LIMIT_LIST_DEFAULT ? "✅ PASS" : "❌ FAIL") . "\n\n";

// Test 2: Reasonable limit (50)
echo "Test 2: Reasonable limit (50)\n";
$queries2 = [Query::limit(50)];
enforceLimits($queries2);
$limit2 = null;
foreach ($queries2 as $q) {
    if ($q->getMethod() === Query::TYPE_LIMIT) {
        $limit2 = $q->getValue();
    }
}
echo "Result: Limit = {$limit2}\n";
echo "Expected: 50\n";
echo ($limit2 === 50 ? "✅ PASS" : "❌ FAIL") . "\n\n";

// Test 3: Excessive limit (10000) - should be capped
echo "Test 3: Excessive limit (10000)\n";
$queries3 = [Query::limit(10000)];
enforceLimits($queries3);
$limit3 = null;
foreach ($queries3 as $q) {
    if ($q->getMethod() === Query::TYPE_LIMIT) {
        $limit3 = $q->getValue();
    }
}
echo "Result: Limit = {$limit3}\n";
echo "Expected: " . APP_LIMIT_LIST_MAX . "\n";
echo ($limit3 === APP_LIMIT_LIST_MAX ? "✅ PASS" : "❌ FAIL") . "\n\n";

// Test 4: Maximum limit (1000) - should pass through
echo "Test 4: Maximum limit (1000)\n";
$queries4 = [Query::limit(1000)];
enforceLimits($queries4);
$limit4 = null;
foreach ($queries4 as $q) {
    if ($q->getMethod() === Query::TYPE_LIMIT) {
        $limit4 = $q->getValue();
    }
}
echo "Result: Limit = {$limit4}\n";
echo "Expected: 1000\n";
echo ($limit4 === 1000 ? "✅ PASS" : "❌ FAIL") . "\n\n";

echo "=== All Tests Complete ===\n";
