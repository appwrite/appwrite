---
layout: post
title: Setting up “Sign in with Google” in your app
description: Learn how to set up Google OAuth2 authentication in your app.
date: 2025-09-09
lastUpdated: 2026-06-29
cover: /images/blog/setting-up-google-signin/cover.avif
timeToRead: 5
author: atharva
category: tutorial
faqs:
  - question: "What do I need from Google Cloud Console to enable Sign in with Google?"
    answer: "You need an OAuth consent screen and an OAuth client ID and secret. The consent screen controls what users see when they sign in, and the client ID and secret identify your project to Google so it can issue tokens to your application."
  - question: "Should I choose Internal or External audience for my OAuth app?"
    answer: "Pick Internal if the app is limited to your Google Workspace organization, since it skips verification. Pick External if anyone outside your organization can sign in. External apps may require Google verification once you cross certain user thresholds or request sensitive scopes."
  - question: "Which OAuth scopes do I need for basic Google sign-in?"
    answer: "For standard sign-in flows, `.../auth/userinfo.email`, `.../auth/userinfo.profile`, and `openid` are enough. They return basic profile information without triggering Google's sensitive-scope review process. Only request additional scopes when you actually need to call Google APIs on the user's behalf."
  - question: "How do I wire Google OAuth into Appwrite?"
    answer: "In the Appwrite Console, go to [Auth](/docs/products/auth) settings, open the Google provider, paste in the client ID and client secret from Google Cloud Console, and save. Then copy the redirect URI Appwrite displays and add it to Authorized Redirect URIs on your Google OAuth client."
  - question: "Can I share my OAuth client ID publicly?"
    answer: "The client ID is treated as public and is fine to ship in client code. The client secret is not, and should only live on the server (or in Appwrite's configuration). If a secret leaks, rotate it in Google Cloud Console immediately."
  - question: "Why is the redirect URI important in Google OAuth?"
    answer: "Google will only redirect users back to URIs explicitly listed in your OAuth client config. If the redirect URI does not match exactly, the sign-in flow fails with an error. For Appwrite, use the redirect URI shown in the Google provider settings, not a custom URL you invent."
---

This article will teach you how to configure **Sign in with Google** in your applications. We will focus on two key parts:

1. Set up and configure an app in your Google Cloud Console to complete the following
    1. OAuth consent screen
    2. OAuth client ID and secret
2. Client-side configuration

If you prefer guides in a video format, we already have a [video](https://youtu.be/tgO_ADSvY1I) you can watch and follow along while integrating.

# Configuring the project on Google Cloud Console

## Setting up a project

To use Google as an OAuth provider, you need to set the following things up in your Google Developer Console:

- **OAuth consent screen**: This is displayed to users when they try to sign in using Google.
- **OAuth client ID and secret**: These are used to identify your Google Cloud project through your app.

Head to [Google Cloud Console](https://console.cloud.google.com) and sign in with your Google Account. After signing in, depending on whether you have already used Google Cloud Console before, you may be asked to create a new project.

## Creating an OAuth Consent Screen

After creating your project, you will be redirected to your project dashboard, which should look similar to the following image.

![Google developer console project dashboard](/images/blog/setting-up-google-signin/google-console.avif)

- Click on **APIs & Services** in the quick access section and then on **OAuth Consent Screen** in the sidebar. If you're doing this for the first time, you should see a page that mentions the Google Auth Platform is not set up. Click on **Get Started**, which should open a wizard that guides you to creating your OAuth consent screen.
- When creating an OAuth consent screen, you will be presented with a wizard that asks a series of questions, starting with your app name and user support email address. Google will share this email with your users for support-related queries. Hit **Next** once you’re ready to proceed to the next step.

![Setting up audience for OAuth consent screen](/images/blog/setting-up-google-signin/audience.avif)

- In the **Audience** section, as shown in the above image, you must mention who will use your application.
    - If your application is meant to be used by a closed group of people, select **Internal**.
    - If you plan on submitting your app to app stores or releasing it for public use (i.e., anyone not in your organization), select **External**. This is important because if you choose external, you might need to submit your app for review once you reach a certain number of users, whereas if you choose internal, no verification is required. For this tutorial, we will choose external.

Once the setup is complete, you can also edit the branding of your consent screen by clicking on the **Branding** option on the sidebar.

## Selecting OAuth scopes

When working with Google OAuth, there are details about the users to whom your app can request access for example email address, profile picture, etc. OAuth scopes determine specifically what you can access and the next step would be to configure these.

Click on **Data Access** on the sidebar and click on **Add or remove scopes**. A menu should now appear displaying all the available scopes you can enable.

![Setting up scopes for OAuth consent screen](/images/blog/setting-up-google-signin/scopes.avif)

For standard usage, you can use `.../auth/userinfo.email`, `.../auth/userinfo.profile` and `openid`.

These scopes allow you to get the very basic information about the user. However, if you want to perform sensitive actions on behalf of your users, you might need to enable sensitive scopes that require immediate review of your application by Google.

## Creating an OAuth client ID

Now that your consent screen is set up, we can create an OAuth Client ID. An OAuth Client ID is used in your application so that Google can identify your project and provide authentication services to your users.

To create an OAuth Client ID, click on **Clients** on the sidebar and then click on **Create Client** on the top bar. This should open another wizard to help you configure your OAuth Client ID.

![Setting up OAuth client ID](/images/blog/setting-up-google-signin/client.avif)

Let’s dive into the fields that you need to enter here:

- **Application type:** the platform you wish to use for your application. In this case, we will select “Web application”.
- **Name:** the name you desire to assign to your OAuth Client ID. This could be anything and does not impact further steps in the tutorial.
- **Authorized JavaScript Origins:** if you chose a web application as your application type, you need to provide your website URL. This should be the front end of your application.
- **Authorized Redirect URIs:** the URLs Google can trust to redirect the users back to. This will depend on how your app and the back end are configured. We will cover this in the next step.

When you have filled all the required fields, click on **Create**. This should process your request and create your OAuth Client ID. Now, go ahead and open the entry for your client and you should see a screen similar to the following. 

![OAuth client ID details](/images/blog/setting-up-google-signin/client-details.avif)

Note the **Client ID** and **Client secret;** we will need them later in this guide. The client ID could be public, but make sure you don’t share your client secret with others.

# Implementing Google authentication in your apps

Once you have created your Google OAuth credentials, you can integrate them into your application and enable your users to authenticate using Google. Client-side integration will be different for every provider and package you use. In our case, we will use Appwrite as our auth provider, which will handle everything related to our users. 

## Setting up Google authentication in the Appwrite console

- Go to [Appwrite Cloud](https://cloud.appwrite.io) and log in using your preferred authentication method. Once you’re logged in, click on **Create Project**.
- Give the project a name, select a desired location (which should be closer to most of your users), and click **Create**. You should now be presented with your project dashboard.

![Appwrite Cloud Console](/images/blog/setting-up-google-signin/appwrite-console.avif)

- Now, click on **Auth** on the sidebar, and click on the **Settings** tab. Here you will see many OAuth providers you can configure for authentication in your application. Click on **Google,** and you will find options to fill out in Appwrite and the Google Cloud Console.

![Setting up Google authentication in Appwrite console](/images/blog/setting-up-google-signin/appwrite-google-oauth.avif)

- Enter the client ID and secret you obtained from the Google Cloud Console. Also, copy the URI and add it to the redirect URIs section of your Google client on Google Cloud Console. Once that's done, click on **Update**. This should enable Google to be an authentication provider in your Appwrite app.

## Initiating Google authentication flow

Now, you can simply initialize an [Appwrite client](/docs/quick-starts/web) and call the following method to trigger Google sign-in using the Appwrite Web SDK.

```jsx
import { Client, Account, OAuthProvider } from "appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<PROJECT_ID>');                 // Your project ID

const account = new Account(client);

// Go to OAuth provider login page
account.createOAuth2Session(
    {
    provider: OAuthProvider.Google,
    success: 'https://example.com/success',
    failure: 'https://example.com/failed'
},
);
```

The `createOAuth2Session()` method from the `account` object allows you to initiate an OAuth authentication session. By running this function, the user will be redirected to Google's authentication servers to authenticate and will be redirected back to your app, depending on how you've configured your success and failure redirect URLs.

If you're facing problems where cookies are not being set after authentication, you should use `createOAuth2Token()` method instead. We have a [troubleshooting guide](/blog/post/fixing-oauth2-issues-in-appwrite-cloud) to help you transition to that flow, which should not be a huge change.

You can use the following method to get more information about the current logged-in session.

```jsx
const session = await account.getSession({
    sessionId: 'current'
});
```

The `getSession()` method will provide you with all the information about the session of the current logged-in user.

# Conclusion

Hopefully, this guide helped you integrate Google authentication functionality in your apps. If you have any questions, feel free to join our [Discord server](https://appwrite.io/discord), and we would be happy to help! Here are some related resources.

- [Understanding OAuth and OpenID Connect](/blog/post/oauth-openid)
- [OAuth 2 login documentation](/docs/products/auth/oauth2)