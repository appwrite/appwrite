Trigger a function execution. The returned object will return you the current execution status. You can ping the `Get Execution` endpoint to get updates on the current execution status. Once this endpoint is called, your function execution process will start asynchronously.

## Async Function Structure

Async functions in Appwrite allow you to handle long-running operations without blocking the client. When you create a function execution, you can specify whether it should run asynchronously using the `async` parameter.

### Understanding Async vs Sync Execution

- **Synchronous execution** (`async: false`, default): The client waits for the function to complete. Maximum execution time is 30 seconds.
- **Asynchronous execution** (`async: true`): The function runs in the background. The client receives an immediate response with the execution ID. Maximum execution time can exceed 30 seconds (up to your configured timeout).

### When to Use Async

Use async execution when your function:
- Takes longer than 30 seconds to complete
- Performs heavy processing or data operations
- Doesn't need to return a result immediately to the client
- Handles batch operations or scheduled tasks

## Complete Async Cloud Function Example

Here's a full example of implementing a long-running async function in Appwrite Cloud:

### 1. Client-Side: Triggering the Async Execution

```javascript
import { Client, Functions } from 'appwrite';

const client = new Client()
  .setEndpoint('https://cloud.appwrite.io/v1')
  .setProject('[PROJECT_ID]');

const functions = new Functions(client);

// Create an async execution for long-running tasks
const execution = await functions.createExecution(
  '[FUNCTION_ID]',
  JSON.stringify({ taskType: 'heavy-processing', data: {...} }),
  true, // async: true - crucial for operations > 30s
  '/',
  'POST'
);

console.log('Execution started:', execution.$id);
console.log('Status:', execution.status); // 'waiting' or 'processing'

// Poll for results
const pollExecution = async (executionId) => {
  const result = await functions.getExecution('[FUNCTION_ID]', executionId);
  
  if (result.status === 'completed') {
    console.log('Function completed:', JSON.parse(result.responseBody));
    return result;
  } else if (result.status === 'failed') {
    console.error('Function failed:', result.stderr);
    throw new Error(result.stderr);
  } else {
    // Still processing, poll again
    await new Promise(resolve => setTimeout(resolve, 2000));
    return pollExecution(executionId);
  }
};

await pollExecution(execution.$id);
```

### 2. Server-Side: Async Function Implementation

```javascript
import { Client, Databases } from 'node-appwrite';

export default async ({ req, res, log, error }) => {
  const client = new Client()
    .setEndpoint(process.env.APPWRITE_FUNCTION_API_ENDPOINT)
    .setProject(process.env.APPWRITE_FUNCTION_PROJECT_ID)
    .setKey(process.env.APPWRITE_API_KEY);

  const databases = new Databases(client);

  try {
    const payload = JSON.parse(req.body);
    log('Starting long-running task:', payload.taskType);

    // Simulate heavy processing (>30 seconds)
    const results = [];
    for (let i = 0; i < 100; i++) {
      // Perform database operations, API calls, etc.
      const item = await databases.createDocument(
        '[DATABASE_ID]',
        '[COLLECTION_ID]',
        'unique()',
        { processed: true, index: i, data: payload.data }
      );
      results.push(item.$id);
      
      // Add delay to simulate processing
      await new Promise(resolve => setTimeout(resolve, 500));
      
      // Log progress
      if (i % 10 === 0) {
        log(`Progress: ${i + 1}/100 items processed`);
      }
    }

    log('Task completed successfully');
    return res.json({
      success: true,
      itemsProcessed: results.length,
      documentIds: results
    });

  } catch (err) {
    error('Function execution failed:', err.message);
    return res.json(
      { success: false, error: err.message },
      500
    );
  }
};
```

## Best Practices for Async Functions

### 1. Handling Executions Longer than 30 Seconds

**Always set `async: true`** for operations that might exceed 30 seconds:

```javascript
const execution = await functions.createExecution(
  functionId,
  payload,
  true, // REQUIRED for >30s executions
);
```

Without `async: true`, the execution will timeout at 30 seconds regardless of your function's configured timeout.

### 2. Error Handling

Implement comprehensive error handling in both client and function:

**Client-side:**
```javascript
try {
  const execution = await functions.createExecution(functionId, data, true);
  const result = await pollExecution(execution.$id);
  // Handle success
} catch (err) {
  // Handle execution failure
  console.error('Execution failed:', err.message);
  // Implement retry logic if appropriate
}
```

**Function-side:**
```javascript
export default async ({ req, res, log, error }) => {
  try {
    // Your function logic
    return res.json({ success: true, data: result });
  } catch (err) {
    error('Error:', err.message, err.stack);
    // Return error response with appropriate status code
    return res.json(
      { success: false, error: err.message },
      500 // HTTP status code
    );
  }
};
```

### 3. Result Polling

Implement efficient polling to check execution status:

```javascript
const pollExecution = async (functionId, executionId, maxAttempts = 60, interval = 2000) => {
  for (let attempt = 0; attempt < maxAttempts; attempt++) {
    const execution = await functions.getExecution(functionId, executionId);
    
    if (execution.status === 'completed') {
      return JSON.parse(execution.responseBody);
    }
    
    if (execution.status === 'failed') {
      throw new Error(execution.stderr || 'Execution failed');
    }
    
    // Status is 'waiting' or 'processing'
    await new Promise(resolve => setTimeout(resolve, interval));
  }
  
  throw new Error('Polling timeout: execution did not complete in time');
};
```

### 4. Logging and Monitoring

Use the provided logging functions to track progress:

```javascript
export default async ({ req, res, log, error }) => {
  log('Function started with payload:', req.body);
  
  try {
    // Log important milestones
    log('Phase 1: Validating data');
    // ... validation logic
    
    log('Phase 2: Processing items');
    // ... processing logic
    
    log('Phase 3: Finalizing results');
    // ... finalization logic
    
    log('Function completed successfully');
    return res.json({ success: true });
  } catch (err) {
    error('Critical error:', err.message);
    throw err;
  }
};
```

### 5. Timeout Configuration

Configure appropriate timeouts in your function settings:

- Default timeout: 15 seconds
- Maximum timeout: Up to 900 seconds (15 minutes) depending on your plan
- Set timeout based on expected execution time plus buffer

**Important:** Even with a high timeout configured, you MUST use `async: true` when creating the execution if it will take longer than 30 seconds.

### 6. Idempotency

Make your async functions idempotent when possible:

```javascript
export default async ({ req, res, log }) => {
  const payload = JSON.parse(req.body);
  const idempotencyKey = payload.idempotencyKey;
  
  // Check if this operation was already processed
  try {
    const existing = await databases.getDocument(
      '[DATABASE_ID]',
      '[COLLECTION_ID]',
      idempotencyKey
    );
    log('Operation already processed, returning cached result');
    return res.json(existing.result);
  } catch {
    // Document doesn't exist, proceed with operation
  }
  
  // Process operation
  const result = await processOperation(payload);
  
  // Store result with idempotency key
  await databases.createDocument(
    '[DATABASE_ID]',
    '[COLLECTION_ID]',
    idempotencyKey,
    { result, processedAt: new Date().toISOString() }
  );
  
  return res.json(result);
};
```

## Common Pitfalls

1. **Forgetting `async: true` for long operations**: Always use async mode for tasks exceeding 30 seconds
2. **Not implementing proper polling**: The client needs to actively check execution status
3. **Inadequate error handling**: Both client and function should handle failures gracefully
4. **Ignoring timeout limits**: Configure function timeout appropriately in settings
5. **Missing logging**: Use log() and error() functions to aid debugging
6. **Blocking operations**: Use async/await properly to avoid blocking the event loop

## Summary

Async functions are essential for long-running operations in Appwrite. Remember:
- Use `async: true` for any execution that might exceed 30 seconds
- Implement proper result polling on the client side
- Add comprehensive error handling and logging
- Configure appropriate timeouts in function settings
- Consider idempotency for critical operations
