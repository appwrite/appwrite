Get a function execution log by its unique ID.

**⚠️ Authentication Required**

This endpoint requires an active user session (client SDK) or API key (server SDK). Guest users cannot access execution logs for security reasons.

**For async executions**, if you need to check execution status:

- **Client SDK (recommended)**: Use [Realtime subscriptions](https://appwrite.io/docs/realtime) to listen for execution updates instead of polling
- **Guest users**: Have your function write results to a database document with public read permissions
- **Server SDK**: Use an API key to poll execution status from your backend