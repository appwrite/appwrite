---
layout: article
title: Rate-limits
description: Optimize application performance with Appwrite rate limits. Explore rate limiting strategies, configurations, and how to prevent abuse of your services.
---

Some of Appwrite's API endpoints have a rate limit to avoid abuse or brute-force attacks against Appwrite's REST API. Each Appwrite route documentation has information about any rate limits that might apply to them.

Rate limits only apply to Client SDKs. Rate limits do not apply when accessing Appwrite with a Server SDK authenticated using an API key.

# Headers {% #headers %}

You can check the returned HTTP headers of any API request to see your current rate limit status:

```http
HTTP/1.1 200
Date: Mon, 01 Jul 2013 17:27:06 GMT
Status: 200
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 56
X-RateLimit-Reset: 1372700873
```

The headers tell you everything you need to know about your current rate limit status:

| Header               | Description                                                                    |
| -------------------- | ------------------------------------------------------------------------------ |
| X-RateLimit-Limit    | The maximum number of requests that the consumer is permitted to make per hour. |
| X-RateLimit-Remaining| The number of requests remaining in the current rate limit window.              |
| X-RateLimit-Reset    | The time at which the current rate limit window resets in UTC epoch seconds.    |

If you need the time in a different format, any modern programming language can get the job done. For example, if you open up the console on your web browser, you can easily get the reset time as a JavaScript Date object. You can also read more about [Unix Time](https://en.wikipedia.org/wiki/Unix_time).

```js
new Date(1372700873 * 1000) // => Mon Jul 01 2013 13:47:53 GMT-0400 (EDT)
```

Once you go over the rate limit you will receive an error response:

```http
HTTP/1.1 429
Date: Tue, 20 Aug 2013 14:50:41 GMT
Status: 429
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 0
X-RateLimit-Reset: 1377013266
{
    "message": "Too many requests",
    "code": 429
}
```

# Dev keys {% #dev-keys %}

Rate limits are necessary to protect your apps and users from abuse; however, they can sometimes add unwanted friction when a developer is trying to repeatedly consume certain Appwrite APIs to test their application in a short period. [Dev keys](/docs/advanced/platform/dev-keys) are a type of secret used by client apps to bypass these rate limits in development environments.

To use dev keys, client apps add a header `X-Appwrite-Dev-Key` containing the secret to all HTTP requests sent to the Appwrite API. Appwrite recognizes this header, verifies the secret, and if valid, allows the request to bypass the rate limit.

```http
X-Appwrite-Dev-Key: 5b0be23...abda7c6
```

Dev keys should never be included in production applications as they can expose your application to abuse. They are meant for development and testing purposes only.

# Service abuse {% #service-abuse %}

To protect the quality of service from Appwrite, additional rate limits may apply to some actions. For example, rapidly creating content, polling aggressively instead of using webhooks, making API calls with a high concurrency, or repeatedly requesting data that is computationally expensive may result in abuse rate limiting.

It is not intended for this rate limit to interfere with any legitimate use of the API. Your normal rate limits should be the only limit you target.

If you are exceeding your rate limit, you can likely fix the issue by caching API responses and using webhooks for data polling.

If your application triggers this rate limit, you'll receive an informative response:

```http
HTTP/1.1 429
Content-Type: application/json; charset=utf-8
Connection: close
{
    "message": "Too many login attempts",
    "code": 429
}
```
