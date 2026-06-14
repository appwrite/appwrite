---
layout: post
title: "How Appwrite handles OAuth: Google, GitHub, and beyond"
description: Learn how the createOAuth2Token flow works in Appwrite, how to configure providers, retrieve user profile data, and handle access token refresh for 30+ providers.
date: 2026-03-25
cover: /images/blog/appwrite-oauth/cover.avif
timeToRead: 5
author: aditya-oberai
category: product, tutorial, security
featured: false
unlisted: true
faqs:
  - question: "How does the createOAuth2Token flow work in Appwrite?"
    answer: "Your app calls account.createOAuth2Token to get an authorization URL, redirects the user there, and the provider sends them back to Appwrite with an authorization code. Appwrite exchanges the code for tokens and redirects back to your success URL with userId and secret query parameters, which you exchange via account.createSession to finalize the session."
  - question: "How many OAuth providers does Appwrite support?"
    answer: "Appwrite supports over 30 OAuth2 providers, including Google, GitHub, Microsoft, Apple, Discord, Slack, Spotify, Twitter/X, and LinkedIn. Each provider is enabled in the Appwrite Console under Auth settings by entering the App ID and App Secret from the OAuth app you registered with that provider."
  - question: "What is the difference between createOAuth2Token and createOAuth2Session?"
    answer: "createOAuth2Token returns an authorization URL and lets your app create the session explicitly via createSession, which gives you control over cookies and token storage and works well for SSR and mobile. createOAuth2Session creates the session directly inside the OAuth flow, with provider info embedded in the session response."
  - question: "How do I access the OAuth provider's access token after login?"
    answer: "With createOAuth2Token, provider details live on the user's identity rather than the session. Use account.listIdentities to retrieve identity records, then read identity.providerAccessToken to call the provider's API. You can also see identity.provider, identity.providerUid, identity.providerEmail, and identity.providerAccessTokenExpiry."
  - question: "How do I refresh an expired OAuth access token?"
    answer: "Check identity.providerAccessTokenExpiry, and when it is close to expiring, call account.updateSession with sessionId set to current. Appwrite uses the stored refresh token to get a new access token from the provider, while the Appwrite session itself remains valid. Note that not all providers issue refresh tokens."
  - question: "Does Appwrite OAuth work for mobile apps?"
    answer: "Yes. createOAuth2Token is the right choice for mobile because there is no browser URL to redirect to. You open the authorization URL in a browser session (such as expo-web-browser on React Native), catch the redirect back to your app via a deep link, and call createSession with the userId and secret from the URL."
---

OAuth login is table stakes for most apps. Users expect to sign in with Google, GitHub, or another provider they already trust. But wiring up OAuth yourself means managing authorization codes, token exchanges, refresh flows, and session state. Get any of it wrong and you've either broken logins or created a security hole.

Appwrite handles the entire OAuth2 flow server-side. You call one SDK method to get an authorization URL, Appwrite does the redirect and token exchange, and you get back credentials to finalize the session. This post explains exactly how that flow works using `createOAuth2Token`, how to configure providers, how to access user profile data, and how to keep access tokens fresh.

# How OAuth2 works in Appwrite

Appwrite's `createOAuth2Token` flow separates the authorization redirect from session creation. This gives you explicit control over when and how a session is established, which is essential for server-side rendered apps, mobile clients, and any scenario where you need to set your own cookies or store tokens explicitly.

Here's the full sequence:

1. Your app calls `account.createOAuth2Token()` with the provider name, redirect URLs, and optional scopes. It returns an authorization URL.
2. You redirect the user to that URL. Appwrite sends them to the provider's authorization page (Google, GitHub, etc.).
3. The user grants permission. The provider redirects back to Appwrite with an authorization code.
4. Appwrite exchanges the code for an access token and refresh token with the provider.
5. Appwrite redirects the user to your success URL with `userId` and `secret` appended as query parameters.
6. Your app reads those parameters and calls `account.createSession({ userId, secret })` to create the Appwrite session.

The access and refresh tokens from the OAuth provider are stored alongside the Appwrite session. Your app never handles raw OAuth tokens directly, which keeps them off the client.

Importantly, OAuth login creates an **[identity](/docs/products/auth/identities)** attached to the user's Appwrite account. A single Appwrite account can have multiple identities: the same user could log in with Google on one device and GitHub on another, and both sessions point to the same account as long as the email address matches.

# Enabling a provider

Every OAuth provider needs to be enabled and configured in your Appwrite project before it can be used.

1. Open the [Appwrite Console](https://cloud.appwrite.io) and select your project.
2. Go to **Auth** > **Settings**.
3. Scroll to **OAuth2 Providers** and click the provider you want to enable.
4. Toggle it on and enter the **App ID** and **App Secret** from the OAuth app you registered with that provider.
5. Copy the **Redirect URI** shown in Appwrite and paste it into your OAuth app's allowed redirect URLs.

Each provider has a slightly different name for these values. Google calls them "Client ID" and "Client Secret." GitHub uses "Client ID" and "Client Secret" as well. The Appwrite docs for each provider include screenshots and step-by-step instructions.

Appwrite supports over 30 providers including Google, GitHub, Microsoft, Apple, Discord, Slack, Spotify, Twitter/X, LinkedIn, and many more.

# Initiating the OAuth flow

Call `createOAuth2Token()` to get the authorization URL, then redirect the user to it:

```js
import { Client, Account, OAuthProvider } from 'appwrite';

const client = new Client()
  .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
  .setProject('<PROJECT_ID>');

const account = new Account(client);

async function loginWithGitHub() {
  const authUrl = await account.createOAuth2Token({
    provider: OAuthProvider.Github,
    success: 'https://yourapp.com/callback',
    failure: 'https://yourapp.com/login?error=oauth',
    scopes: ['read:user', 'user:email']
  });

  window.location.href = authUrl;
}
```

The `scopes` array is optional. If you only need basic authentication (name, email), you usually don't need custom scopes. If you want to call the provider's API on behalf of the user, add the scopes required for those API calls.

For Google with Calendar access:

```js
const authUrl = await account.createOAuth2Token({
  provider: OAuthProvider.Google,
  success: 'https://yourapp.com/callback',
  failure: 'https://yourapp.com/login?error=oauth',
  scopes: ['https://www.googleapis.com/auth/calendar.readonly']
});

window.location.href = authUrl;
```

# Completing the flow by creating session

When Appwrite redirects the user to your success URL, it appends `userId` and `secret` as query parameters. Read these and exchange them for a session:

```js
// On your callback page (e.g. https://yourapp.com/callback)
const params = new URLSearchParams(window.location.search);
const userId = params.get('userId');
const secret = params.get('secret');

if (userId && secret) {
  const session = await account.createSession({ userId, secret });
  // User is now logged in, redirect to your app
  window.location.href = '/dashboard';
}
```

This two-step approach means you decide when and how the session is stored. In a Next.js or SvelteKit app, you can handle this server-side and set an HTTP-only cookie. In a React Native app, you control token storage explicitly.

# Getting user and provider data

After the session is created, fetch the Appwrite account to get the user's name and email, which Appwrite populates from the OAuth provider during login:

```js
const user = await account.get();

console.log(user.name);   // display name from provider
console.log(user.email);  // email from provider
```

Provider-specific details (the provider name, the user's ID on the provider, and the OAuth access token) are not on the session object in this flow. With `createOAuth2Token`, the session is created separately by your app via `createSession`, decoupled from the OAuth exchange itself. With `createOAuth2Session`, Appwrite creates the session directly as part of the OAuth flow, so provider info is embedded in the session response. Because `createOAuth2Token` separates these steps, provider details live on the identity instead. Use the [Identities API](/docs/products/auth/identities) to retrieve them:

```js
const { identities } = await account.listIdentities();
const identity = identities[0];

console.log(identity.provider);           // 'github'
console.log(identity.providerUid);        // the user's ID on the provider
console.log(identity.providerEmail);      // the user's email on the provider
console.log(identity.providerAccessToken); // OAuth access token
```

# Calling the provider's API

If you requested extra scopes, you can use `identity.providerAccessToken` to call the provider's own API directly from your frontend or backend.

For example, fetching a user's GitHub repositories:

```js
const { identities } = await account.listIdentities();
const identity = identities.find(i => i.provider === 'github');

const response = await fetch('https://api.github.com/user/repos', {
  headers: {
    Authorization: `Bearer ${identity.providerAccessToken}`,
    Accept: 'application/vnd.github.v3+json'
  }
});

const repos = await response.json();
```

Or listing Google Calendar events:

```js
const { identities } = await account.listIdentities();
const identity = identities.find(i => i.provider === 'google');

const response = await fetch(
  'https://www.googleapis.com/calendar/v3/calendars/primary/events',
  {
    headers: {
      Authorization: `Bearer ${identity.providerAccessToken}`
    }
  }
);

const events = await response.json();
```

# Refreshing access tokens

OAuth access tokens expire. Check `identity.providerAccessTokenExpiry` before making provider API calls, and refresh the token when it's close to expiring. Refreshing is done via the session using `account.updateSession`:

```js
async function getFreshAccessToken(provider) {
  const { identities } = await account.listIdentities();
  let identity = identities.find(i => i.provider === provider);

  const expiry = new Date(identity.providerAccessTokenExpiry);
  const minutesUntilExpiry = (expiry - new Date()) / 1000 / 60;

  if (minutesUntilExpiry < 5) {
    await account.updateSession({ sessionId: 'current' });
    // Re-fetch identities to get the updated token
    const refreshed = await account.listIdentities();
    identity = refreshed.identities.find(i => i.provider === provider);
  }

  return identity.providerAccessToken;
}
```

`account.updateSession` uses the stored refresh token to get a new access token from the provider. The Appwrite session itself remains valid throughout; only the provider access token is refreshed.

Note that not all providers issue refresh tokens. Google does by default. GitHub access tokens don't expire (unless the OAuth app is configured with expiring tokens). Check the provider's documentation to understand their token lifecycle.

# Managing identities

A user's connected OAuth providers appear as identities on their account. You can list them:

```js
const { identities } = await account.listIdentities();

identities.forEach(identity => {
  console.log(identity.provider, identity.providerEmail);
});
```

Users can connect additional providers by going through the `createOAuth2Token` flow again while already logged in. This links the new provider to the existing account.

To disconnect a provider:

```js
await account.deleteIdentity({ identityId });
```

# Mobile and native app setup

On mobile platforms, the OAuth redirect needs special handling because there's no browser URL to redirect to. `createOAuth2Token` is the right choice here: you get the authorization URL, open it in a browser session, and catch the redirect back to your app.

For React Native with Expo:

```js
import { makeRedirectUri } from 'expo-auth-session';
import * as WebBrowser from 'expo-web-browser';

const deepLink = new URL(makeRedirectUri({ preferLocalhost: true }));
const scheme = `${deepLink.protocol}//`;

const authUrl = await account.createOAuth2Token({
  provider: OAuthProvider.Github,
  success: `${deepLink}`,
  failure: `${deepLink}`
});

const result = await WebBrowser.openAuthSessionAsync(`${authUrl}`, scheme);

const url = new URL(result.url);
const secret = url.searchParams.get('secret');
const userId = url.searchParams.get('userId');

await account.createSession({ userId, secret });
```

For other platforms:

- **Flutter**: Set the success and failure URLs to your app's deep link scheme, such as `appwrite-callback-<PROJECT_ID>://`.
- **Android**: Register an intent filter for the callback scheme in `AndroidManifest.xml`.
- **Apple (iOS/macOS)**: Register a custom URL scheme in your app's Info.plist.

The Appwrite docs for each platform include exact configuration steps.

One thing to note: OAuth login is not available via the GraphQL API. Use the REST API or SDK.

# Start building with Appwrite OAuth

Appwrite's `createOAuth2Token` flow gives you full control over session creation while handling the hard parts: authorization redirects, token exchange, and refresh. You get a consistent API across 30+ providers without managing any of the OAuth complexity yourself.

- [Appwrite OAuth2 docs](/docs/products/auth/oauth2)
- [Identities docs](/docs/products/auth/identities)
- [Appwrite Auth overview](/docs/products/auth)
- [Start building on Appwrite Cloud](https://cloud.appwrite.io)
