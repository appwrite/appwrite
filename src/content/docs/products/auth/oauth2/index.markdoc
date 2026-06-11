---
layout: article
title: OAuth 2 login
description: Integrate OAuth2 authentication seamlessly with Appwrite. Learn how to connect your application with third-party OAuth2 providers for secure user login and access.
---

OAuth authentication allows users to log in using accounts from other popular services. This can be convenient for users because they can start using your app without creating a new account. It can also be more secure, because the user has one less password that could become vulnerable.

When using OAuth to authenticate, the authentication request is initiated from the client application. The user is then redirected to an OAuth 2 provider to complete the authentication step, and finally, the user is redirected back to the client application.

{% info title="Identities and OAuth2" %}
OAuth2 login creates an **identity** in Appwrite, allowing users to connect multiple providers to a single account. Learn more in [Identities](/docs/products/auth/identities).
{% /info %}

# Configure OAuth 2 login {% #configure %}

Before using OAuth 2 login, you need to enable and configure an OAuth 2 login provider.

1. Navigate to your Appwrite project.
2. Navigate to **Auth** > **Settings**.
3. Find and open the OAuth provider.
4. In the OAuth 2 settings modal, use the toggle to enable the provider.
5. Create and OAuth 2 app on the provider's developer platform.
6. Copy information from your OAuth2 provider's developer platform to fill the **OAuth2 Settings** modal in the Appwrite Console.
7. Configure redirect URL in your OAuth 2 provider's developer platform. Set it to URL provided to you by **OAuth2 Settings** modal in Appwrite Console.

# Initialize OAuth 2 login {% #init %}

To initialize the OAuth 2 login process, use the [Create OAuth 2 Session](/docs/references/cloud/client-web/account#createOAuth2Session) route.

OAuth2 sessions allow you to specify the scope of the access you want to request from the OAuth2 provider.
The requested scopes describe which resources a session can access.

You can pass the scopes to request through the `scopes` parameter when creating a session.
The scope is provider-specific and can be found in the provider's documentation.

{% tabs %}
{% tabsitem #js title="Javascript" %}
```client-web
import { Client, Account, OAuthProvider } from "appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<PROJECT_ID>');                 // Your project ID

const account = new Account(client);

// Go to OAuth provider login page
account.createOAuth2Session({
    provider: OAuthProvider.Github,
    success: 'https://example.com/success', // redirect here on success
    failure: 'https://example.com/failed', // redirect here on failure
    scopes: ['repo', 'user'] // scopes (optional)
});
```
{% /tabsitem %}

{% tabsitem #flutter title="Flutter" %}

For Android, add the following activity inside the `<application>` tag in your `AndroidManifest.xml`. Replace `<PROJECT_ID>` with your actual Appwrite project ID.

```xml
<!-- Add this inside the <application> tag, along side the existing <activity> tags -->
<activity android:exported="true" android:name="com.linusu.flutter_web_auth_2.CallbackActivity" >
  <intent-filter android:label="flutter_web_auth_2">
    <action android:name="android.intent.action.VIEW" />
    <category android:name="android.intent.category.DEFAULT" />
    <category android:name="android.intent.category.BROWSABLE" />
    <data android:scheme="appwrite-callback-<PROJECT_ID>" />
  </intent-filter>
</activity>
```

No other configuration is required for iOS.

```client-flutter
import 'package:appwrite/appwrite.dart';
import 'package:appwrite/enums.dart';

final client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<PROJECT_ID>');               // Your project ID

final account = Account(client);

// Go to OAuth provider login page
await account.createOAuth2Session({
    provider: OAuthProvider.github,
    scopes: ['repo', 'user']
});
```
{% /tabsitem %}

{% tabsitem #apple title="Apple" %}
For Apple, add the following URL scheme to your `Info.plist`.

```xml
<key>CFBundleURLTypes</key>
<array>
<dict>
    <key>CFBundleTypeRole</key>
    <string>Editor</string>
    <key>CFBundleURLName</key>
    <string>io.appwrite</string>
    <key>CFBundleURLSchemes</key>
    <array>
        <string>appwrite-callback-<PROJECT_ID></string>
    </array>
</dict>
</array>
```
If you're using UIKit, you'll also need to add a hook to your `SceneDelegate.swift` file to ensure cookies work correctly.

```client-apple
func scene(_ scene: UIScene, openURLContexts URLContexts: Set<UIOpenURLContext>) {
    guard let url = URLContexts.first?.url,
        url.absoluteString.contains("appwrite-callback") else {
        return
    }
    WebAuthComponent.handleIncomingCookie(from: url)
}
```

```client-apple
import Appwrite
import AppwriteEnums

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<PROJECT_ID>")                // Your project ID

let account = Account(client)

// Go to OAuth provider login page
try await account.createOAuth2Session(
    provider: .github,
    scopes: ['repo', 'user']
)
```
{% /tabsitem %}

{% tabsitem #android title="Android" %}
For Android, add the following activity inside the `<application>` tag in your `AndroidManifest.xml`.
Replace `<PROJECT_ID>` with your actual Appwrite project ID.

```xml
<!-- Add this inside the `<application>` tag, along side the existing `<activity>` tags -->
<activity android:name="io.appwrite.views.CallbackActivity" android:exported="true">
  <intent-filter android:label="android_web_auth">
    <action android:name="android.intent.action.VIEW" />
    <category android:name="android.intent.category.DEFAULT" />
    <category android:name="android.intent.category.BROWSABLE" />
    <data android:scheme="appwrite-callback-<PROJECT_ID>" />
  </intent-filter>
</activity>
```
```client-android-kotlin
import io.appwrite.Client
import io.appwrite.services.Account
import io.appwrite.enums.OAuthProvider

val client = Client(context)                     // Activity or application context
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<PROJECT_ID>")                  // Your project ID

val account = Account(client)

// Go to OAuth provider login page
account.createOAuth2Session(
    provider = OAuthProvider.GITHUB,
    scopes = listOf('repo', 'user')
)
```
{% /tabsitem %}

{% tabsitem #react-native title="React Native" %}
If using Expo, set the URL scheme to `appwrite-callback-<PROJECT_ID>` in your `app.json` file.

```json
{
  "expo": {
    "scheme": "appwrite-callback-<PROJECT_ID>"
  }
}
```

Then, create a deep link, pass it to `account.createOAuth2Token()` method to create the login URL, open the URL in a browser, listen for the redirect, and finally create a session with the secret.

```client-react-native
import { Client, Account, OAuthProvider } from "appwrite";
import { makeRedirectUri } from 'expo-auth-session'
import * as WebBrowser from 'expo-web-browser';

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<PROJECT_ID>');                          // Your project ID

const account = new Account(client);

// Create deep link that works across Expo environments
// Ensure localhost is used for the hostname to validation error for success/failure URLs
const deepLink = new URL(makeRedirectUri({ preferLocalhost: true }));
const scheme = `${deepLink.protocol}//`; // e.g. 'exp://' or 'appwrite-callback-<PROJECT_ID>://'

// Start OAuth flow
const loginUrl = await account.createOAuth2Token({
    provider,
    success: `${deepLink}`,
    failure: `${deepLink}`,
});

// Open loginUrl and listen for the scheme redirect
const result = await WebBrowser.openAuthSessionAsync(`${loginUrl}`, scheme);

// Extract credentials from OAuth redirect URL
const url = new URL(result.url);
const secret = url.searchParams.get('secret');
const userId = url.searchParams.get('userId');

// Create session with OAuth credentials
await account.createSession({
    userId,
    secret
});
// Redirect as needed
```
{% /tabsitem %}
{% /tabs %}

You'll be redirected to the OAuth 2 provider's login page to log in. Once complete, your user will be redirected back to your app.

You can optionally configure `success` or `failure` redirect links on web to handle success and failure scenarios.

# OAuth 2 profile {% #profile %}

After authenticating a user through their OAuth 2 provider, you can fetch their profile information such as their avatar image or name. To do this you can use the access token from the OAuth 2 provider and make API calls to the provider.

After creating an OAuth 2 session, you can fetch the session to get information about the provider.

{% info title="Tip" %}
Replace `[SESSION_ID]` with either `"current"` to get or update the active session, or with a specific session ID.
{% /info %}

{% multicode %}
```client-web
import { Client, Account } from "appwrite";

const client = new Client();

const account = new Account(client);

const session = await account.getSession({
    sessionId: 'current'
});

// Provider information
console.log(session.provider);
console.log(session.providerUid);
console.log(session.providerAccessToken);
```

```client-flutter
import 'package:appwrite/appwrite.dart';

final client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<PROJECT_ID>');               // Your project ID

final account = Account(client);

final session = await getSession(
    sessionId : "<SESSION_ID>"
);

// Provider information
print(session.provider);
print(session.providerUid);
print(session.providerAccessToken);
```
```client-apple
import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<PROJECT_ID>") // Your project ID

let account = Account(client)

let session = try await account.getSession(
    sessionId: "<SESSION_ID>"
)

// Provider information
print(session.provider);
print(session.providerUid);
print(session.providerAccessToken);
```
```client-android-kotlin
import io.appwrite.Client
import io.appwrite.services.Account

val client = Client(context)
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<PROJECT_ID>") // Your project ID

val account = Account(client)

val response = account.getSession(
    sessionId = "<SESSION_ID>"
)

// Provider information
print(session.provider);
print(session.providerUid);
print(session.providerAccessToken);
```
{% /multicode %}


An OAuth 2 [session](/docs/references/cloud/models/session) will have the following columns.

| Property                   | Description                                                                                               |
| -------------------------- | --------------------------------------------------------------------------------------------------------- |
| provider                   | The OAuth2 Provider.                                                                                       |
| providerUid                | User ID from the OAuth 2 Provider.                                                                         |
| providerAccessToken        | Access token from the OAuth 2 provider. Use this to **make requests to the OAuth 2 provider** to fetch personal data. |
| providerAccessTokenExpiry  | Check this value to know if an access token is about to expire.                                            |

You can use the `providerAccessToken` to make requests to your OAuth 2 provider. Refer to the docs for the OAuth 2 provider you're using to learn about making API calls with the access token.

# Refresh tokens {% #refresh-tokens %}

OAuth 2 sessions expire to protect from security risks.
This means the OAuth 2 session with a provider may expire, even when an Appwrite session remains active.
OAuth 2 sessions should be refreshed periodically so access tokens don't expire.

Check the value of `providerAccessTokenExpiry` to know if the token is expired or is about to expire.
You can refresh the provider session by calling the [Update OAuth Session](/docs/references/cloud/client-web/account#updateSession) endpoint whenever your user visits your app.
Avoid refreshing before every request, which might cause rate limit problems.

{% multicode %}
```client-web
const promise = account.updateSession({
    sessionId: '[SESSION_ID]'
});

promise.then(function (response) {
    console.log(response); // Success
}, function (error) {
    console.log(error); // Failure
});
```
```client-flutter
import 'package:appwrite/appwrite.dart';

final client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<PROJECT_ID>');                // Your project ID

Account account = Account(client);

final result = await account.updateSession(
    sessionId: '<SESSION_ID>'
);
```
```client-apple
import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<PROJECT_ID>") // Your project ID

let account = Account(client)

let session = try await account.updateSession(
    sessionId: "<SESSION_ID>"
);
```
```client-android-kotlin
import io.appwrite.Client
import io.appwrite.services.Account

val client = Client(context)
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<PROJECT_ID>")                 // Your project ID

val account = Account(client)

val response = account.updateSession(
    sessionId = "<SESSION_ID>"
);
```

{% /multicode %}

{% info title="GraphQL" %}
OAuth 2 is not available through the GraphQL API. You can use the REST API or any Client SDK instead.
{% /info %}