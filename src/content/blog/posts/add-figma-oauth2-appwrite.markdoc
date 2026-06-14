---
layout: post
title: How to add Figma OAuth2 login to your app with Appwrite
description: Learn how to set up Figma OAuth2 login to your app with Appwrite.
date: 2025-05-07
cover: /images/blog/add-figma-oauth2-appwrite/cover.avif
timeToRead: 5
author: ebenezer-don
category: tutorial
featured: false
faqs:
  - question: "Does Appwrite support OAuth2 login with Figma out of the box?"
    answer: "Yes. Figma is one of the OAuth2 providers built into [Appwrite Auth](/docs/products/auth/oauth2). You register an OAuth app on the Figma developer portal, paste the Client ID and Secret into the Appwrite console, copy the redirect URL back to Figma, and the integration is done."
  - question: "Do I need server-side code to use Figma OAuth2 with Appwrite?"
    answer: "No. Appwrite's SDKs handle the OAuth2 redirect, code exchange, and session creation. You call `account.createOAuth2Session({ provider: OAuthProvider.Figma, success, failure })` from your client, and the user lands back in your app with an active Appwrite session."
  - question: "How do I call the Figma API after a user logs in?"
    answer: "Appwrite stores the OAuth access token and refresh token alongside the user's session. You can retrieve them via the SDK and use the access token to call Figma's REST API on behalf of the user. Appwrite can also refresh expired access tokens for you using the stored refresh token."
  - question: "What scopes should I request from Figma?"
    answer: "Request only the scopes your app actually uses. For reading files, `files:read` is enough. Asking for more permissions than you need slows down the consent screen and risks rejection during Figma's app review for sensitive scopes."
  - question: "Can I combine Figma OAuth2 with other auth methods?"
    answer: "Yes. A single Appwrite user can have multiple identities linked, so a user who signed up with email/password can later add Figma OAuth2, or vice versa. This is useful when you want Figma as an optional connection rather than a primary login method."
  - question: "What other OAuth2 providers does Appwrite support?"
    answer: "Appwrite supports over 30 OAuth2 providers out of the box, including Google, GitHub, Apple, Microsoft, Discord, Slack, Spotify, and more. The full list is in the [OAuth2 docs](/docs/products/auth/oauth2). The setup steps are the same for each: create an app on the provider, paste the credentials into Appwrite, and you are done."
---

Figma's API opens up some interesting doors, such as automating workflows, syncing design tokens, and bringing live design previews into your app. But before you can do any of that, your users need to connect their Figma account. If you're using Appwrite for authentication, this process is simpler than you might expect. In this guide, we'll walk through everything you need to do to support Figma OAuth2 login inside your Appwrite project.

We'll cover both the configuration and the actual login flow, then show how to securely access Figma's API on behalf of the user. You won't need any server-side code because Appwrite handles the OAuth2 flow for you, and sessions are maintained like any other login provider.

# Understanding the OAuth flow in Appwrite

When a user attempts to sign in with Figma, Appwrite starts a standard OAuth2 flow:

1. It redirects the user to Figma with the necessary query parameters (`client_id`, `redirect_uri`, `scope`, and `state`).
2. Once the user grants access, Figma sends back an authorization code.
3. Appwrite exchanges that code for an access token and refresh token.
4. A new Appwrite session is created for the user.
5. That session includes the user's identity and also stores the Figma tokens, so your frontend can later call the Figma API if needed.

The access token is used to make requests to Figma's API. Appwrite also stores the refresh token and allows you to renew the access token when it expires. This is optional and only necessary if you continue to use Figma's API beyond the initial login.

# Creating your OAuth app in Figma

To connect your Appwrite project to Figma, the first step is to register your app on the [Figma Developer Portal](https://www.figma.com/developers/apps). This allows you to request access to a user's Figma data through OAuth2.

Click **"Create a new app"** in the top right.

You'll be asked to fill in a few details:

- **Name** - This will be shown to users during login.
- **Website** - A link to your app or landing page.
- **Logo** - Optional, but it helps users recognize your app in the consent screen.

![Figma Create App](/images/blog/add-figma-oauth2-appwrite/figma-create-app.avif)

Once you save the app, Figma will show your **Client ID** and **Client Secret**. These are the credentials you'll need to complete the setup in Appwrite.

![Figma Client ID and Secret](/images/blog/add-figma-oauth2-appwrite/figma-client-info.avif)

Take a moment to copy both values. The **Client Secret** is shown only once, so make sure to save it somewhere you can reference later.

Now that the app is registered, find it in your list of apps and click to open it. Inside the modal, switch to the **OAuth 2.0** section and click **Add a redirect URL**. This is where you'll paste the callback URL that Appwrite generates in the next step.

Keep this tab open. We'll come right back to it.

# Enabling Figma as a provider in Appwrite

Now let's switch over to Appwrite.

1. Open the [Appwrite Console](https://cloud.appwrite.io/) and choose your project.
2. In the left-hand sidebar, click **Auth**.
3. Inside the Auth section, select the **Settings** tab.
4. Scroll down to the **OAuth2 Providers** section.
5. Click on **Figma**.

This opens a configuration panel where you'll see:

- A toggle to enable the Figma provider.
- Input fields for **App ID** and **App Secret**.
- A read-only **Redirect URI** that Appwrite will use for the callback.

Copy the Redirect URI and return to your Figma app configuration. Under **OAuth 2.0**, paste it into the redirect URL field and save the changes.

Once that's done, head back to Appwrite, paste in your **Client ID** and **Client Secret**, enable the toggle, and click **Update**.

![Appwrite Figma Provider](/images/blog/add-figma-oauth2-appwrite/appwrite-figma-oauth-screen.avif)

At this point, Figma is connected to your Appwrite backend.

# Logging in from your frontend

Once Figma is enabled as a provider, you can trigger a login using the Appwrite SDK. Here's an example using the JavaScript SDK in a React app:

```js
import { Client, Account, OAuthProvider } from 'appwrite'

const client = new Client()
  .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
  .setProject('your-project-id')

const account = new Account(client)

function signInWithFigma() {
  account.createOAuth2Session({
    provider: OAuthProvider.Figma,
    success: '<https://your-app.com/redirect-after-success>',
    failure: '<https://your-app.com/redirect-after-failure>',
    scopes: ['files:read'] // scopes (optional)
  })
}
```

This will redirect the user to Figma's consent screen. Once they log in and approve access, they'll be returned to your app with an active Appwrite session.

# Calling the Figma API (if needed)

If your app just needs Figma for login, you don't have to think about the Figma token again. Appwrite already pulled the identity info (name, email, etc.) when the session was created.

But if you want to call Figma's API after the initial login, either to fetch files, sync variables, or manage libraries, you'll need a valid access token. Figma's tokens expire after 24 hours, but Appwrite stores the refresh token and gives you a way to renew them:

```js
async function getFigmaFiles() {
  // Ensure token is fresh
  await account.updateSession({
    sessionId: 'current'
  });
  const session = await account.getSession({
    sessionId: 'current'
  });
  const token = session.providerAccessToken

  const res = await fetch('https://api.figma.com/v1/files/file_key', {
    headers: {
      Authorization: `Bearer ${token}`,
    },
  })

  if (!res.ok) {
    throw new Error('Figma API error')
  }

  return res.json()
}
```

The `updateSession()` call silently refreshes the Figma token using the stored refresh token. Your user remains logged in to your app, so they won't see any interruptions.

# What happens when the Figma token expires?

Appwrite keeps the user logged in with their Appwrite session. Even after Figma's access token expires, your app's session remains valid. The only thing that stops working is the ability to make requests to Figma's API.

So if your app never talks to Figma again, you don't have to refresh anything. But if you need to access the API after the token has expired, calling `updateSession()` is the way to renew it.

# Common issues to watch for

**Redirect URI mismatch**
If the redirect URI you added in Figma doesn't match exactly what Appwrite is using, including protocol, port, or trailing slashes, Figma will reject the login with a `redirect_uri mismatch` error.

**Invalid client credentials**
If you see an `invalid_client` error, it usually means your Client ID or Secret is incorrect, or you didn't copy the secret when it was first shown.

**Expired token when calling Figma API**
That's expected if the token has expired. Just call `updateSession('current')` before you hit the Figma endpoint again.

**Scope mismatch**
Make sure the scopes you request in `createOAuth2Session()` match what you enabled in Figma. Otherwise, Figma will block the request.

# Logging out

To log the user out and remove access to their Figma account:

```js
await account.deleteSession({
    sessionId: 'current'
})
```

This clears both the Appwrite session and the associated access and refresh tokens.

# Final thoughts

You don't need a custom backend to support "Sign in with Figma." Appwrite gives you a simple way to plug in Figma as an OAuth2 provider, handle authentication, and optionally access the Figma API, all with session management handled for you.

If you're only using Figma for sign-in, your work is done once the session is created. But if your app needs to interact with Figma's API after that, Appwrite gives you a safe way to refresh tokens on demand.

If you have any questions or run into any issues, feel free to reach out to the team through the [Appwrite Discord server](https://appwrite.io/discord).

# Further reading

- [Appwrite OAuth2 docs](https://appwrite.io/docs/client/auth#oauth2)
- [Figma API docs](https://www.figma.com/developers/api)
- [How to set up Google authentication in React with Appwrite](https://appwrite.io/blog/post/set-up-google-auth-appwrite-react)