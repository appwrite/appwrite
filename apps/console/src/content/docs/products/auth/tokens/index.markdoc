---
layout: article
title: Tokens
description: What are tokens and how to use them in Appwrite
---
Tokens are short-lived secrets created by an [Appwrite Server SDK](/docs/sdks#server) that can be exchanged for session by a [Client SDK](/docs/sdks#client) to log in users.
Some auth methods like [Magic URL login](/docs/products/auth/magic-url), 
[Email OTP login](/docs/products/auth/email-otp), or [Phone (SMS) login](/docs/products/auth/phone-sms) already generate tokens.

You can also create custom tokens using the [Create token](/docs/products/auth/custom-token) 
endpoint of the [Users API](/docs/products/auth/users). This can be used to implement **custom authentication flows**.

Tokens are created with the following properties:

| Property                                                                                                                             | Type                                                                                                                                 | Description                                                                                                                          |
| ------------------------------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------ |
| `$id`                                                                                                                                | string                                                                                                                               | Token ID.                                                                                                                            |
| `$createdAt`                                                                                                                         | string                                                                                                                               | Token creation date in ISO 8601 format.                                                                                              |
| `userId`                                                                                                                             | string                                                                                                                               | User ID.                                                                                                                             |
| `secret`                                                                                                                             | string                                                                                                                               | Token secret key. This will return an empty string unless the response is returned using an API key or as part of a webhook payload. |
| `expire`                                                                                                                             | string                                                                                                                               | Token expiration date in ISO 8601 format.                                                                                            |

Many Appwrite authentication methods use a token-base flow to authenticate users. For token-based authentication methods, there are two high level steps to authenticate a user:

# Token login {% #token-login %}
You can find different usage of tokens in the Appwrite.

{% cards %}
{% cards_item href="/docs/products/auth/custom-token" title="Custom token login" %}
{% /cards_item %}
{% cards_item href="/docs/products/auth/email-otp" title="Email OTP login" %}
{% /cards_item %}
{% cards_item href="/docs/products/auth/magic-url" title="Email magic URL" %}
{% /cards_item %}
{% cards_item href="/docs/products/auth/phone-sms" title="Phone (SMS) OTP" %}
{% /cards_item %}
{% /cards %}
