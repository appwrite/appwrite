---
layout: post
title: Handle CORS errors in Appwrite Functions
description: Learn how to handle CORS errors in Appwrite Functions.
date: 2024-07-08
cover: /images/blog/handle-cors-in-serverless-functions/cover.avif
timeToRead: 3
author: ebenezer-don
category: product
featured: false
faqs:
  - question: "Why do I get a CORS error when calling my Appwrite Function from the browser?"
    answer: "By default, your function returns the response without the CORS headers the browser expects. The browser blocks the response unless `Access-Control-Allow-Origin` (and related headers) match the origin making the request. The fix is to add these headers to the function's response directly."
  - question: "Why doesn't `res.setHeader()` work in Appwrite Functions?"
    answer: "Appwrite Functions use a different response model than Express. Headers must be passed as the third argument to response methods like `res.send()` or `res.json()` instead of being set on a mutable response object. Calling `res.setHeader()` will not attach the header to the outgoing response."
  - question: "How do I handle CORS preflight requests in [Appwrite Functions](/docs/products/functions)?"
    answer: "Check if `req.method === 'OPTIONS'` at the top of your function and return an empty response with the allowed origin, methods, and headers. Browsers send this preflight before any non-simple cross-origin request to verify it's allowed."
  - question: "Is it safe to use `Access-Control-Allow-Origin: *` in production?"
    answer: "No. The wildcard lets any origin call your function, which exposes it to abuse and CSRF-style attacks. Use it only during local development, and pin the header to your specific domain(s) before deploying."
  - question: "Which CORS headers do I need to set besides `Access-Control-Allow-Origin`?"
    answer: "For preflight requests, also set `Access-Control-Allow-Methods` (the HTTP verbs you accept) and `Access-Control-Allow-Headers` (custom headers like `Content-Type` or `Authorization` that your client sends). For the actual response, `Access-Control-Allow-Origin` is usually enough."
  - question: "Do I need CORS headers if I call my function from the Appwrite SDK?"
    answer: "If you call the function through the Appwrite client SDK using `functions.createExecution()`, the request goes through the Appwrite API, which handles CORS for you. CORS only becomes an issue when the browser fetches the function URL directly across origins."
---

Cross-Origin Resource Sharing (CORS) is a security feature that allows web applications to interact securely with resources from different origins and denies unwanted communication. You might be reading this because your browser has blocked access to your Appwrite serverless function. This guide will help you "unblock" that access.

# Understanding CORS

CORS controls how web applications interact with resources from different origins, preventing unauthorised requests from malicious websites. To allow cross-origin requests, servers must include specific headers in their responses that indicate which origins are permitted to access the resources.

## **How CORS works**

When a browser makes a cross-origin request, it includes an Origin header with the request. The server can respond with various CORS headers to specify:

- Which origins are allowed (`Access-Control-Allow-Origin`)
- Which methods are allowed (`Access-Control-Allow-Methods`)
- Which headers are allowed (`Access-Control-Allow-Headers`)

For certain types of requests, the browser sends a preflight (OPTIONS) request to check if the actual request is safe to send.

# CORS in Appwrite serverless functions

In traditional Node.js and Express setups, CORS headers are often set using `res.setHeader()`. However, in Appwrite serverless functions, this approach won't work due to the way Appwrite handles responses. Instead, you should directly include CORS headers in the response returned by the function.

## Steps to handle CORS

### 1. Create and deploy your serverless function

Start by creating and deploying your serverless function through the Appwrite console or CLI. This ensures your function is set up correctly within the Appwrite environment. Detailed steps can be found in the [Appwrite Functions documentation](https://appwrite.io/docs/products/functions).

### 2. Handle preflight (OPTIONS) requests

Preflight requests are sent by browsers to verify permissions before making actual requests. To handle these requests, check if the incoming request is an OPTIONS request and respond with the appropriate CORS headers:

```javascript
if (req.method === 'OPTIONS') {
  return res.send('', 200, {
    'Access-Control-Allow-Origin': 'YOUR_DOMAIN_HERE',
    'Access-Control-Allow-Methods': 'POST, GET, OPTIONS',
    'Access-Control-Allow-Headers': 'Content-Type, Authorization',
  })
}

```

### 3. Include CORS headers in responses

For other requests (POST, GET, etc.), include the `Access-Control-Allow-Origin` header in the response to specify which origins are allowed to access the resource:

```javascript
if (req.method === 'POST') {
  try {
    return res.json({ ok: true }, 200, {
      'Access-Control-Allow-Origin': 'YOUR_DOMAIN_HERE',
    })
  } catch (error) {
    return res.json({ error: 'Internal Server Error' }, 500, {
      'Access-Control-Allow-Origin': 'YOUR_DOMAIN_HERE',
    })
  }
}
```


# Using the wildcard `*` to allow all origins

It is possible to use the wildcard `*` in the `Access-Control-Allow-Origin` header to allow any origin to access the resource.
While this simplifies development, it can pose security risks. For this reason, you might encounter errors when using the wildcard `*` in your Appwrite production environment.

When developing locally, here's how you might configure CORS to allow access from any origin:

```javascript
return res.json(completion, 200, {
  'Access-Control-Allow-Origin': '*',
})
```

For better security, replace `*` with your specific domain name to restrict access to only trusted origins:

```javascript
return res.json(completion, 200, {
  'Access-Control-Allow-Origin': 'YOUR_DOMAIN_HERE',
})

```

This way, only requests originating from your domain will be allowed.

# Conclusion

Handling CORS in Appwrite serverless functions ensures secure communication between your web applications and backend resources. By understanding how CORS works, you can prevent unauthorized requests and protect your serverless functions from potential security threats.

- [Get started with Appwrite](https://cloud.appwrite.io/)
- [Explore the Functions documentation](https://appwrite.io/docs/products/functions)
- [Join our Discord community](https://discord.com/invite/appwrite)
