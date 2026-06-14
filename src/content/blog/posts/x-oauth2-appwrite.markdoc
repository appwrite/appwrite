---
layout: post
title: Announcing X OAuth support in Appwrite Auth
description: Appwrite now supports X (formerly Twitter) OAuth2 login. Learn how to add it to your app in minutes.
date: 2026-04-09
cover: /images/blog/x-oauth2-appwrite/cover.avif
timeToRead: 5
author: aditya-oberai
category: announcement, tutorial
featured: false
faqs:
  - question: "How do I add X (Twitter) login to my Appwrite app?"
    answer: "Register an app in the X Developer Console, copy the Client ID and Client Secret, then open your Appwrite project, go to Auth, Settings, OAuth2 Providers, and enable X with those credentials. Paste Appwrite's redirect URI back into your X app's callback settings and save. See [Appwrite Auth](/docs/products/auth) for the full flow."
  - question: "Does Appwrite use PKCE for X OAuth?"
    answer: "Yes. The X adapter uses OAuth 2.0 with PKCE (Proof Key for Code Exchange), which X's API v2 requires. PKCE adds protection against authorization code interception, particularly for mobile and single-page apps."
  - question: "Can I call the X API on behalf of a user after they sign in?"
    answer: "Yes. Appwrite stores the access token and refresh token from the OAuth flow, and you can retrieve them via the Account API to call X endpoints (such as reading the user's profile or posts) on their behalf, scoped to the permissions you requested."
  - question: "What scopes should I request from X?"
    answer: "Start with Read at a minimum, which gives you basic profile and timeline access. Only request elevated permissions like Write (posting) or Direct Message access if your product actually uses them, since extra scopes increase the friction of the consent screen and the risk surface if a token leaks."
  - question: "Do I need to write any backend code for X OAuth in Appwrite?"
    answer: "No. Appwrite handles the redirect, code exchange, token storage, and session creation. From the frontend you call a single SDK method to get the authorization URL, then create the session on the callback page using the userId and secret in the query string."
  - question: "What other OAuth providers does Appwrite support?"
    answer: "Appwrite Auth supports a wide list of OAuth2 providers including Google, GitHub, Apple, Facebook, Microsoft, Discord, and many more, all configured the same way from the Appwrite Console. See [Appwrite Auth](/docs/products/auth) for the full list and setup steps."
---

We're excited to announce that Appwrite Auth now includes an X OAuth adapter. You can now let users sign in with their X account using Appwrite's built-in OAuth2 support, with no custom backend code required.

X is one of the most widely used social platforms, with hundreds of millions of active users. Adding "Sign in with X" gives your users a fast, familiar way to get started without creating a new account, and gives you a verified identity to work with from day one.

In this guide, we'll walk through what this means for your app, why it matters, and how to set it up.

# Why X OAuth is useful for developers and users

For users, social login removes the friction of registration. There's no new password to create or forget, no verification email to wait for. They click one button, approve access, and they're in.

For developers, social login with Appwrite means you don't have to implement or maintain any OAuth infrastructure yourself. Appwrite handles the redirect, the token exchange, the session creation, and the refresh flow. You call one SDK method. The rest happens server-side.

X in particular is valuable for apps that are social or content-focused. If your users are already on X, letting them authenticate with it creates a natural connection between their X identity and your product. You can also use the access token Appwrite stores to call the X API on their behalf, enabling things like reading their profile, fetching their posts, or building X-connected features.

# How OAuth2 works in Appwrite

When a user signs in with X, Appwrite manages the entire OAuth2 flow on your behalf:

1. Your app calls an Appwrite SDK method, which returns an authorization URL.
2. The user is redirected to X's consent screen.
3. After granting access, X redirects back to Appwrite with an authorization code.
4. Appwrite exchanges the code for an access token and refresh token with X.
5. Appwrite redirects the user to your success URL with a `userId` and `secret`.
6. Your app uses these to create an Appwrite session.

Appwrite's X adapter uses **OAuth 2.0 with PKCE** (Proof Key for Code Exchange), which is required by X's API v2 and adds an extra layer of security by preventing authorization code interception attacks.

# Creating an X Developer app

To connect Appwrite to X, you first need to register an app on the [X Developer Console](https://console.x.com).

Log in and create a new project, then create a new app inside that project (or use an existing one). Give your app a name that reflects what you're building.

![X Developer Console - New App](/images/integrations/oauth-x/new-app.avif)

Once the app is created, open the app's **Settings** and scroll down to **User authentication settings**. Click **Set up** and configure the following:

- **App permissions**: Select **Read** at a minimum. If your app needs to post or access direct messages, select the appropriate permissions.
- **Type of App**: Select **Web App, Automated App or Bot**.
- **Callback URI / Redirect URL**: Temporarily add `https://temporary-endpoint.com/`. You'll replace this with the real URI from Appwrite in the next step.

![X OAuth2 user authentication settings](/images/integrations/oauth-x/oauth2.avif)

After saving, X will display a **Client ID** and **Client Secret**. Save both. The Client Secret is only shown once, so copy it somewhere safe before closing the page.

# Enabling X as a provider in Appwrite

Head to your [Appwrite Console](https://cloud.appwrite.io/) and open your project. Navigate to **Auth** > **Settings**, scroll to **OAuth2 Providers**, and click on **X**.

![X OAuth2 provider in Appwrite](/images/integrations/oauth-x/provider.avif)

Enable the provider and paste in your **Client ID** and **Client Secret**. Appwrite will display a **Redirect URI**. Copy it and go back to your X app's **User authentication settings** to replace the temporary callback URL with this value.

Save the changes in both the X Developer Console and Appwrite.

# Logging in from your frontend

With the provider configured, you can trigger X login using the Appwrite SDK. Here's an example using the JavaScript SDK:

```js
import { Client, Account, OAuthProvider } from 'appwrite';

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

const account = new Account(client);

const authUrl = await account.createOAuth2Token({
    provider: OAuthProvider.X,
    success: 'https://your-app.com/auth/callback',
    failure: 'https://your-app.com/auth/login?error=oauth'
});

window.location.href = authUrl;
```

Then on your callback page, read the `userId` and `secret` from the query string and create the session manually:

```js
const params = new URLSearchParams(window.location.search);
const userId = params.get('userId');
const secret = params.get('secret');

if (userId && secret) {
    await account.createSession({ userId, secret });
    window.location.href = '/dashboard';
}
```

# Accessing user data

After login, you can fetch the authenticated user's profile from Appwrite:

```js
const user = await account.get();

console.log(user.name);   // display name from X
console.log(user.email);  // email from X (if granted)
```

If you need the X access token to call the X API directly, retrieve it from the user's identities:

```js
const { identities } = await account.listIdentities();
const xIdentity = identities.find(i => i.provider === 'x');

console.log(xIdentity.providerAccessToken); // X OAuth2 access token
```

You can use this access token to make requests to the [X API v2](https://docs.x.com) on behalf of the user.

# Refreshing the access token

X access tokens expire. When you need a fresh token, call `updateSession` to silently renew it using the stored refresh token:

```js
await account.updateSession({ sessionId: 'current' });

const { identities } = await account.listIdentities();
const xIdentity = identities.find(i => i.provider === 'x');

console.log(xIdentity.providerAccessToken); // fresh token
```

This renews the X access token without interrupting the user's Appwrite session.

# Final thoughts

Adding X login to your app with Appwrite comes down to three things: registering an app on the X Developer Console, configuring the provider in the Appwrite Console, and calling one SDK method. Appwrite handles the PKCE flow, token exchange, and session management for you.

If you have questions or run into issues, the [Appwrite Discord server](https://appwrite.io/discord) is the best place to get help.

# Further reading

- [X OAuth integration guide](/integrations/oauth-x)
- [Appwrite Auth OAuth2 docs](/docs/products/auth/oauth2)
- [X Developer Console](https://console.x.com)
- [X API OAuth2 documentation](https://docs.x.com/fundamentals/authentication/oauth-2-0/overview)
- [Understanding OAuth and OpenID Connect](/blog/post/oauth-openid)
- [Appwrite Auth API reference](/docs/references/cloud/client-web/account)
