---
layout: article
title: Error handling
description: Best practices for handling Appwrite errors in your applications. Learn how to provide friendly error messages to your users while effectively troubleshooting issues.
---

When integrating Appwrite into your applications, proper error handling is important for delivering a good user experience while still being able to troubleshoot issues effectively.

# Consider user-friendly error messages

**It's generally best to avoid returning Appwrite's raw error messages directly to your users.** These messages are designed for developers and may contain technical details that:

- Could confuse non-technical users
- Might expose implementation details
- Often create an inconsistent user experience

Instead, consider this approach:

1. Catch errors from Appwrite
2. Identify the error type
3. Return a user-friendly message appropriate for your application

# Use error types for better handling

Appwrite returns not just HTTP status codes but also specific error types that provide more precise information about what went wrong. These error types are available in the `type` field of the error response:

```json
{
    "message": "Invalid credentials. Please check the email and password.",
    "type": "user_invalid_credentials",
    "code": 401
}
```

By checking the error type rather than just the status code, you can create more precise error handling logic. This approach allows you to:

- Display contextual error messages based on what actually went wrong
- Implement different recovery strategies for different error types
- Log specific errors for debugging while showing friendly messages to users
- Handle temporary issues (like network problems) differently from permanent ones

# Common error types and suggested messages

Here are some examples of how you might map Appwrite error types to user-friendly messages:

| Error Type | Technical Meaning | User-Friendly Message |
|------------|-------------------|----------------------|
| `user_invalid_credentials` | Invalid credentials. Please check the email and password. | "The email or password you entered is incorrect. Please try again." |
| `user_blocked` | The current user has been blocked. | "Your account has been temporarily suspended. Please contact support." |
| `general_rate_limit_exceeded` | Rate limit for the current endpoint has been exceeded. | "Please wait a moment before trying again." |
| `storage_file_not_found` | The requested file could not be found. | "The file you requested is not available." |
| `row_not_found` | Row with the requested ID could not be found. | "The information you're looking for could not be found." |

# Complete list of error types

Appwrite provides a comprehensive list of error types organized by category. For a complete reference, see the [Response codes](/docs/advanced/platform/response-codes#error-types) documentation.

# Recommended practices

- **Log the actual errors server-side** for debugging while returning friendly messages to users
- **Handle common error scenarios** with specific user guidance
- **Provide clear next steps** when possible (e.g., "Try resetting your password")
- **Use consistent error message styling** throughout your application
- **Consider different error categories** (validation errors, authentication issues, server problems)
- **Consider implementing retry logic** for transient errors like rate limiting (429) or service unavailability (503)

By implementing thoughtful error handling, you improve both the user experience of your application and your ability to troubleshoot issues effectively.