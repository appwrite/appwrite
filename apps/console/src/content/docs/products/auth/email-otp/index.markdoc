---
layout: article
title: Email OTP
description: Seamless sign in with Email OTP authentication in Appwrite. Learn how to provide simple and secure passwordless user accounts.
---

Email OTP (one-time password) authentication lets users create accounts using their email address and log in using a 6 digit code delivered to their email inbox. This method is similar to [Magic URL login](/docs/products/auth/magic-url), but can provide better user experience in some scenarios.

{% info title="Email OTP vs Magic URL" %}
Email OTP sends an email with a 6 digit code that user needs to enter into the app, while Magic URL delivers a clickable button or a link to user's inbox.
Both allow passwordless login flows with different advantages.

| Benefits of Email OTP                                              | Downsides of Email OTP                   |
|--------------------------------------------------------------------|------------------------------------------|
| Doesn't require user to be signed into email inbox on the device   | Expires quicker                        |
| Doesn't disturb application flow with a redirect                   | Requires more inputs from user         |
| Doesn't require deep linking on mobile apps                        |                                          |

{% /info %}

# Send email {% #send-email %}

Email OTP authentication is done using a two-step authentication process. The authentication request is initiated from the client application and an email message is sent to the user's email inbox. The email will contain a 6-digit number the user can use to log in.

Send an email to initiate the authentication process. If the email address has never been used, a **new account is created** using the provided `userId`. Otherwise, if the email address is already attached to an account, the **user ID is ignored**. Then, the user will receive an email with the one-time password.

{% multicode %}

```client-web
import { Client, Account, ID } from "appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

const account = new Account(client);

const sessionToken = await account.createEmailToken({
    userId: ID.unique(),
    email: 'email@example.com'
});

const userId = sessionToken.userId;
```

```client-flutter
import 'package:appwrite/appwrite.dart';

final client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

final account = Account(client);

final sessionToken = await account.createEmailToken(
    userId: ID.unique(),
    email: 'email@example.com'
);

final userId = sessionToken.userId;
```
```client-apple
import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>");

let account = Account(client);

let sessionToken = try await account.createEmailToken(
    userId: ID.unique(),
    email: "email@example.com"
);

let userId = sessionToken.userId;
```

```client-android-kotlin
import io.appwrite.Client
import io.appwrite.services.Account
import io.appwrite.ID

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>");

val account = Account(client);

val sessionToken = account.createEmailToken(
    userId = ID.unique(),
    email = "email@example.com"
);

val userId = sessionToken.userId;
```
```graphql
mutation {
    accountCreateEmailToken(userId: "unique()", email: "email@example.com") {
        _id
        userId
        secret
        expire
    }
}
```

{% /multicode %}

# Login {% #login %}

After initiating the email OTP authentication process, the returned user ID and secret are used to authenticate the user. The user will use their 6-digit one-time password to log in to your app.

{% multicode %}

```client-web
import { Client, Account, ID } from "appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

const account = new Account(client);

const session = await account.createSession({
    userId: userId,
    secret: '<SECRET>'
});
```

```client-flutter
import 'package:appwrite/appwrite.dart';

final client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

final account = Account(client);

final session = await account.createSession(
    userId: userId,
    secret: '<SECRET>'
);
```
```client-apple
import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>");

let account = Account(client);

let session = try await account.createSession(
    userId: userId,
    secret: "<SECRET>"
);
```
```client-android-kotlin
import io.appwrite.Client
import io.appwrite.services.Account
import io.appwrite.ID

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>");

val account = Account(client);

val session = account.createSession(
    userId = userId,
    secret = "<SECRET>"
);
```

```graphql
mutation {
    accountcreateSession(userId: "<USER_ID>", secret: "<SECRET>") {
        _id
        userId
        provider
        expire
    }
}
```

{% /multicode %}

After the secret is verified, a session will be created.

# Security phrase {% #security-phrase %}

A security phrase is a randomly generated phrase provided on the login page, as well as inside Email OTP login email. Users must match the phrase on the login page with the phrase provided inside the email. Security phrases offer protection for various types of phishing and man-in-the-middle attacks.

By default, security phrases are disabled. To enable a security phrase in Email OTP, enable it in first step of the authentication flow.

{% multicode %}
```client-web
import { Client, Account, ID } from "appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<PROJECT_ID>');                 // Your project ID

const account = new Account(client);

const promise = account.createEmailToken({
        userId: ID.unique(),
        email: 'email@example.com',
        phrase: true
    });

promise.then(function (response) {
    console.log(response); // Success
}, function (error) {
    console.log(error); // Failure
});
```

```graphql
mutation {
    accountCreateEmailToken(
        userId: "ID.unique()",
        email: "email@example.com",
        phrase: true
    ) {
        _id
        _createdAt
        userId
        secret
        expire
        phrase
    }
}
```

{% /multicode %}

By enabling security phrase feature, you will recieve `phrase` in the response. You need to display this phrase to the user, and we recommend informing user what this phrase is and how to check it. When security phrase is enabled, email will also include a new section providing user with the security phrase.