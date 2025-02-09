The Functions service allows you to create custom behaviour that can be triggered by any supported Appwrite system events or by a predefined schedule.

Appwrite Cloud Functions lets you automatically run backend code in response to events triggered by Appwrite or by setting it to be executed in a predefined schedule. Your code is stored in a secure way on your Appwrite instance and is executed in an isolated environment.

You can learn more by following our [Cloud Functions tutorial](https://appwrite.io/docs/functions).

## Function Context

When your function is executed, it receives a context object that contains information about the current execution. This includes:

- Request headers and body
- Environment variables
- Project information
- Execution ID (unique identifier for each function execution)
- Event information (if triggered by an event)
- User information (if executed with user context)

### Available Context Information

| Variable | Description |
|----------|-------------|
| APPWRITE_FUNCTION_ID | The ID of the function |
| APPWRITE_FUNCTION_NAME | The name of the function |
| APPWRITE_FUNCTION_DEPLOYMENT | The deployment ID |
| APPWRITE_FUNCTION_EXECUTION_ID | Unique identifier for the current execution |
| APPWRITE_FUNCTION_TRIGGER | What triggered the function (http, event, schedule) |
| APPWRITE_FUNCTION_RUNTIME_NAME | The runtime name (PHP, Node.js, Python, etc.) |
| APPWRITE_FUNCTION_RUNTIME_VERSION | The runtime version |
| APPWRITE_FUNCTION_EVENT | The event that triggered the function (if any) |
| APPWRITE_FUNCTION_EVENT_DATA | The event data (if triggered by an event) |
| APPWRITE_FUNCTION_DATA | The request data (if triggered via HTTP) |
| APPWRITE_FUNCTION_USER_ID | The ID of the user who triggered the function (if any) |
| APPWRITE_FUNCTION_PROJECT_ID | The ID of the project |

These variables are available in all supported runtimes (PHP, Node.js, Python, Ruby, Swift, and Dart) through their respective context objects.