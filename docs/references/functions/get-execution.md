Get a function execution log by its unique ID.

## Session and Permission Requirements

**Important:** Only the user who created an execution can retrieve its status using `functions.getExecution()`. This requires a valid user session - guest users cannot poll execution status.

### Why This Matters

When implementing async function execution patterns (where you create an execution and poll for its completion), you must ensure the client has an authenticated session. The function's execute permissions (e.g., `any` or `guests`) only control who can **create** executions, not who can **read** them.

### Error Example

If a guest user or unauthenticated client attempts to poll an execution, you'll receive:

```javascript
AppwriteException: User (role: guests) missing scope (execution.read)
```

### Correct Implementation

#### Step 1: Ensure User Authentication

```javascript
import { Client, Account, Functions } from 'appwrite';

const client = new Client()
  .setEndpoint('https://cloud.appwrite.io/v1')
  .setProject('[PROJECT_ID]');

const account = new Account(client);
const functions = new Functions(client);

// User must be authenticated
const session = await account.createEmailPasswordSession('user@example.com', 'password');
```

#### Step 2: Create and Poll Execution

```javascript
// Create the execution (user session is required)
const execution = await functions.createExecution(
  '[FUNCTION_ID]',
  JSON.stringify({ key: 'value' }), // data
  false // async execution
);

const executionId = execution.$id;

// Poll for completion (requires same authenticated user)
const pollExecution = async () => {
  try {
    const result = await functions.getExecution('[FUNCTION_ID]', executionId);
    
    if (result.status === 'completed') {
      console.log('Execution completed:', result.responseBody);
      return result;
    } else if (result.status === 'failed') {
      console.error('Execution failed:', result.errors);
      return result;
    } else {
      // Still processing, poll again
      setTimeout(pollExecution, 1000);
    }
  } catch (error) {
    console.error('Error polling execution:', error);
  }
};

pollExecution();
```

### Troubleshooting Permission Errors

| Issue | Solution |
|-------|----------|
| `User (role: guests) missing scope (execution.read)` | Ensure the user is authenticated with a valid session before calling `getExecution()` |
| Execution created but cannot be retrieved | Verify the same user who created the execution is polling it |
| Works in server SDK but not client SDK | Server SDKs use API keys and bypass user permissions; client SDKs require user sessions |

### Alternative Pattern: Use Database for Results

If you need guest users to access execution results, consider having your function write results to a database document with appropriate permissions:

```javascript
// In your function code
const databases = new Databases(client);
await databases.createDocument(
  '[DATABASE_ID]',
  '[COLLECTION_ID]',
  documentId,
  { result: 'function output' },
  [
    Permission.read(Role.any()), // Allow anyone to read results
  ]
);
```

Then clients can poll the database document instead of the execution object.
