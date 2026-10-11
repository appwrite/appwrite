---
layout: article
title: Verify user
description: Learn about Appwrite's email and phone verification system, including verification flows and role-based access control.
---

User verification in Appwrite allows you to verify user email addresses and phone numbers. Users don't need to be verified to log in, but you can restrict resource access to verified users only using permissions.

# Verify email {% #verify-email %}

To verify a user's email, first ensure the user is logged in so that the verification email can be sent to the user who created the account. Then, send the verification email specifying a redirect URL. The verification secrets will be appended as query parameters to the redirect URL.

{% multicode %}
```client-web
import { Client, Account } from "appwrite";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>') // Your project ID

const account = new Account(client);

const promise = account.createVerification({
    url: 'https://example.com/verify'
});

promise.then(function (response) {
    console.log(response); // Success
}, function (error) {
    console.log(error); // Failure
});
```

```client-flutter
import 'package:appwrite/appwrite.dart';

void main() {
  Client client = Client()
    .setEndpoint('https://cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

  Account account = Account(client);

  Future result = account.createVerification(
    url: 'https://example.com/verify'
  );

  result.then((response) {
    print(response);
  }).catchError((error) {
    print(error.response);
  });
}
```

```client-apple
import Appwrite

let client = Client()
    .setEndpoint("https://cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")

let account = Account(client)

let token = try await account.createVerification(
    url: "https://example.com/verify"
)
```

```client-android-kotlin
import io.appwrite.Client
import io.appwrite.services.Account

val client = Client(context)
    .setEndpoint("https://cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")

val account = Account(client)

val response = account.createVerification(
    url = "https://example.com/verify"
)
```
{% /multicode %}

After the user clicks the link in the email, they will be redirected to your site with the query parameters `userId` and `secret`. If you're on a mobile platform, you will need to create the appropriate deep link to handle the verification.

Next, implement the verification page that handles the redirect.

{% multicode %}
```client-web
import { Client, Account } from "appwrite";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

const account = new Account(client);

const urlParams = new URLSearchParams(window.location.search);
const secret = urlParams.get('secret');
const userId = urlParams.get('userId');

const promise = account.updateVerification({
    userId,
    secret
});

promise.then(function (response) {
    console.log(response); // Success
}, function (error) {
    console.log(error); // Failure
});
```

```client-flutter
import 'package:appwrite/appwrite.dart';

void main() {
  Client client = Client()
    .setEndpoint('https://cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

  Account account = Account(client);

  Future result = account.updateVerification(
    userId: '<USER_ID>',
    secret: '<SECRET>'
  );

  result.then((response) {
    print(response);
  }).catchError((error) {
    print(error.response);
  });
}
```

```client-apple
import Appwrite

let client = Client()
    .setEndpoint("https://cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")

let account = Account(client)

let response = try await account.updateVerification(
    userId: "<USER_ID>",
    secret: "<SECRET>"
)
```

```client-android-kotlin
import io.appwrite.Client
import io.appwrite.services.Account

val client = Client(context)
    .setEndpoint("https://cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")

val account = Account(client)

val response = account.updateVerification(
    userId = "<USER_ID>",
    secret = "<SECRET>"
)
```
{% /multicode %}

# Verify phone {% #verify-phone %}

To verify a phone number, first ensure the user is logged in and has a phone number set on their account.

{% multicode %}
```client-web
const response = await account.updatePhone({
    phone: '+12065550100',
    password: 'password'
});
```

```client-flutter
Future result = account.updatePhone(
    phone: '+12065550100',
    password: 'password'
);

result.then((response) {
    print(response);
}).catchError((error) {
    print(error.response);
});
```

```client-apple
let response = try await account.updatePhone(
    phone: "+12065550100",
    password: "password"
)
```

```client-android-kotlin
val response = account.updatePhone(
    phone = "+12065550100",
    password = "password"
)
```
{% /multicode %}

Then initiate verification by calling `createPhoneVerification`.

{% multicode %}
```client-web
const response = await account.createPhoneVerification();
```

```client-flutter
Future result = account.createPhoneVerification();

result.then((response) {
    print(response);
}).catchError((error) {
    print(error.response);
});
```

```client-apple
let response = try await account.createPhoneVerification()
```

```client-android-kotlin
val response = account.createPhoneVerification()
```
{% /multicode %}

After the user receives the verification code, complete verification by calling `updatePhoneVerification`.

{% multicode %}
```client-web
const response = await account.updatePhoneVerification({
    userId: '[USER_ID]',
    secret: '[SECRET]'
});
```

```client-flutter
Future result = account.updatePhoneVerification(
    userId: '<USER_ID>',
    secret: '<SECRET>'
);

result.then((response) {
    print(response);
}).catchError((error) {
    print(error.response);
});
```

```client-apple
let response = try await account.updatePhoneVerification(
    userId: "<USER_ID>",
    secret: "<SECRET>"
)
```

```client-android-kotlin
val response = account.updatePhoneVerification(
    userId = "<USER_ID>",
    secret = "<SECRET>"
)
```
{% /multicode %}

# Restrict access {% #restrict-access %}

You can restrict resource access to verified users in two ways:
- Use `user([USER_ID], "verified")` to restrict access to a specific verified user
- Use `users("verified")` to restrict access to any verified user

# Verification events {% #verification-events %}

The following events are triggered during the verification process:

- `users.*.verification.*` - Triggers on any user's verification token event
- `users.*.verification.*.create` - Triggers when a verification token for a user is created
- `users.*.verification.*.update` - Triggers when a verification token for a user is validated

Each event returns a Token Object.
