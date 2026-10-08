---
layout: post
title: "Error: User (role: guests) missing scope (account) - What it means and how to fix it"
description: Understanding and resolving a common authentication error in Appwrite.
date: 2025-03-07
cover: /images/blog/user-role-guests-missing-scope-account/cover.avif
timeToRead: 5
author: ebenezer-don
category: debugging
faqs:
  - question: "What does User (role: guests) missing scope (account) mean?"
    answer: "It means the request hit Appwrite without an authenticated session, so the caller is treated as a guest. The endpoint you called requires an account scope, which only logged in users have. You need to create or restore a session before making the call."
  - question: "Why do I get this error right after calling account.get()?"
    answer: "Usually because there is no active session in the current SDK client. Either the user has not logged in yet, the session cookie was not sent, or you are calling account.get on a fresh server side client that has not been authenticated for this request."
  - question: "How do I fix this in OAuth2 web apps with cross domain cookies?"
    answer: "Either allow third party cookies in the browser during local development, configure a [custom domain](/docs/advanced/platform/custom-domains) so cookies become first party, or switch to OAuth2 token based auth with createOAuth2Token which falls back to local storage."
  - question: "How do I handle this on the server side in SSR frameworks?"
    answer: "Server side sessions are not persisted automatically. Create the session, capture the secret returned by Appwrite, store it in your own session store or cookie, and attach it to subsequent server side clients. See the [Appwrite Auth](/docs/products/auth) docs for the SSR pattern."
  - question: "Is it ever safe to ignore this error?"
    answer: "Yes. On app startup it is the simplest way to detect that no user is logged in. Catch the exception from account.get and redirect to your login screen. The error is only a real problem when you expected an authenticated session to exist."
  - question: "Can I prevent the error by checking for a session first?"
    answer: "There is no separate is logged in endpoint. The idiomatic pattern is to call account.get inside a try/catch, treat the missing scope error as no session, and treat a successful response as a logged in user."
---

If you've been working with Appwrite, you've likely encountered the error message **"Error: User (role: guests) missing scope (account)"** at some point. This error is one of the most common questions we see from developers, especially those new to Appwrite. In this post, we'll break down what this error means and walk through the various scenarios where it might appear, along with their solutions.

# Understanding the error message

Before diving into specific scenarios, let's understand what the error message is telling us:

- **User (role: guests)**: This indicates that the request is coming from an unauthenticated user (a guest) rather than a logged-in user.
- **missing scope (account)**: This means the operation you're trying to perform requires access to an account.

In essence, this error occurs when you're trying to perform an operation that requires authentication, but Appwrite doesn't detect a valid authenticated session.

There are a handful of reasons you may be receiving this error, which we'll explore in detail below.

# No active session

The most common cause of this error is attempting to access user account information when there's no active session.

If you're not logged in yet and you try to get information about the user with something like `account.get()`, you'll see this error.

```jsx
// This will fail if no user is logged in
try {
  const user = await account.get();
  console.log(user);
} catch (error) {
  console.error(error); // Error: User (role: guests) missing scope (account)
}
```

This error is actually expected and useful in certain scenarios, such as when your app first starts and you need to check if a user is logged in. You can catch this error to determine that there's no active session and redirect users to a login page.

However, if you're getting this error when you actually need an authenticated session, simply create a session before calling `account.get()`:

```jsx
// First create a session
await account.createEmailPasswordSession({
    email: 'user@example.com',
    password: 'password'
});

// Now this will work
const user = await account.get();
console.log(user);
```

# OAuth2 sessions in web applications

A more complex scenario occurs when using OAuth2 authentication in web applications. When you use `account.createOAuth2Session()` in a web app, a cookie is set for the session.

If your app and Appwrite endpoint are on different domains (for example, your app is on `localhost:3000` and you're using `https://cloud.appwrite.io/v1` as your Appwrite endpoint), the cookie may not be included in the `account.get()` request because the cookie is considered a 3rd party cookie.

There are two approaches to solve this issue:

## Option 1: Browser settings and custom domains

For local development, you'll need to allow 3rd party cookies in your browser settings. Many browsers have specific development settings to allow cross-domain cookies during local development.

When moving to production, configure a [custom domain](https://appwrite.io/docs/advanced/platform/custom-domains) for your Appwrite project so that cookies are sent from the same domain as your application. This ensures that session cookies are properly sent with each request.

## Option 2: Use OAuth2 tokens

Another approach is to use Appwrite's OAuth2 token-based authentication, which bypasses third-party cookie restrictions entirely. Instead of `createOAuth2Session()`, you would use `createOAuth2Token()` which uses local storage as a fallback where cookies won't work.

For a detailed implementation guide, check out this article on [Fixing OAuth2 authentication issues in Appwrite Cloud](https://appwrite.io/blog/post/fixing-oauth2-issues-in-appwrite-cloud?doFollow=true), which covers this approach in detail.

Keep in mind that non-public files still require session cookies. If your app needs to handle such files, you'll need to configure a custom domain to ensure cookies are treated as first-party cookies.

# Creating sessions server side

In client-side applications, Appwrite automatically persists the session by using cookies. Server-side, however, different frameworks handle sessions differently so the session is not persisted.

Since `account.createEmailPasswordSession()` doesn't persist a session, calling `account.get()` right after will result in an unauthenticated request:

```jsx
// Server-side code - this approach doesn't work as expected
await account.createEmailPasswordSession({
  email: 'user@example.com',
  password: 'password'
});
// In a different request or function call:
const user = await account.get(); // Error: missing scope (account)
```

For server-side environments, you need to handle session persistence manually:

1. Use an API key when authenticating so that the response from Appwrite includes a secret you can use for subsequent requests
2. Store and reuse the session information between requests

For detailed implementation examples and best practices for server-side rendering with Appwrite, check out the [SSR documentation](https://appwrite.io/docs/products/auth/server-side-rendering?doFollow=true).

# Conclusion

Whenever you see "User (role: guests) missing scope (account)", remember: Appwrite expected an authenticated user but received a guest request instead. This typically happens because:

1. No session exists yet
2. The session cookie is being blocked by browser privacy settings
3. Server-side code isn't properly managing session persistence

Each scenario has a straightforward solution as we've covered above. Test your authentication flow across multiple browsers and environments to ensure it works consistently.

If you're still running into issues, the Appwrite [Discord community](https://appwrite.io/discord) is always available to help.

# Further reading

- [Building custom authentication flows with Appwrite](https://appwrite.io/blog/post/building-custom-auth-flows?doFollow=true)
- [Fixing OAuth2 authentication issues in Appwrite Cloud](https://appwrite.io/blog/post/fixing-oauth2-issues-in-appwrite-cloud?doFollow=true)
- [A modern developer's guide to user authentication](https://appwrite.io/blog/post/guide-to-user-authentication?doFollow=true)
