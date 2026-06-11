{% info title="Account vs Users API" %}
The Account API is the API you should use in your **client applications** with [Client SDKs](/docs/sdks#client) like web, Flutter, mobile, and native apps.
Account API creates sessions, which represent an authenticated user and is attached to a user's [account](/docs/products/auth/accounts).
Sessions respect [permissions](/docs/advanced/platform/permissions), which means users can only access resources if they have been granted the correct permissions.

The Users API is a dedicated API for managing users from an admin's perspective.
It should be used with backend or server-side applications with [Server SDKs](/docs/sdks#server). Users API uses API keys instead of sessions.
This means they're not restricted by permissions, but by the scopes granted to the API key used.
{% /info %}
