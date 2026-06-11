---
layout: article
title: Email and password login
description: Implement email and password authentication with Appwrite. Securely register and authenticate users in your applications using Appwrite's robust email-based authentication system.
---

Email and password login is the most commonly used authentication method. Appwrite Authentication promotes a safer internet by providing secure APIs and promoting better password choices to end users. Appwrite supports added security features like password strength requirements, blocking personal info in passwords, password dictionary, and password history to help users choose good passwords. You can also restrict which addresses can sign up by enabling [email policies](/docs/products/auth/email-policies) to block free, aliased, or disposable email providers.

# Signup {% #sign-up %}

You can use the Appwrite Client SDKs to create an account using email and password.

```client-web
import { Client, Account, ID } from "appwrite";

const client = new Client()
    .setProject('<PROJECT_ID>') // Your project ID
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1');

const account = new Account(client);

try {
    const user = await account.create({
        userId: '[USER_ID]',
        email: 'email@example.com',
        password: '<Password>'
    });
    console.log(user)
} catch (e){
    console.error(e)
}

```

Passwords are hashed with [Argon2](https://github.com/P-H-C/phc-winner-argon2), a resilient and secure password hashing algorithm.

# Login {% #login %}

After an account is created, users can be logged in using the Create Email Session route.

```client-web
import { Client, Account } from "appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const account = new Account(client);

const result = await account.createEmailPasswordSession({
    email: 'email@example.com',
    password: 'password'
});

console.log(result);

```

# Verification {% #verification %}

After logging in, the email can be verified through the account create verification route. The user doesn't need to be verified to log in, but you can restrict resource access to verified users only using permissions through the `user([USER_ID], "verified")` role.

First, send a verification email. Specify a redirect URL which users will be redirected to. The verification secrets will be appended as query parameters to the redirect URL. In this example, the redirect URL is `https://example.com/verify`.

```client-web
import { Client, Account } from "appwrite";

const client = new Client()
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

Next, implement the verification page in your app. This page will parse the secrets passed in through the `userId` and `secret` query parameters. In this example, the code below will be found in the page served at `https://example.com/verify`.

Since the secrets are passed in through url params, it will be easiest to perform this step in the browser.

```client-web
import { Client, Account } from "appwrite";

const client = new Client()
    .setProject('<PROJECT_ID>'); // Your project ID

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

# Password Recovery {% #password-recovery %}

If a user forgets their password, they can initiate a password recovery flow to recover their password. The Create Password Recovery endpoint sends the user an email with a temporary secret key for password reset. When the user clicks the confirmation link, they are redirected back to the password reset URL with the secret key and email address values attached to the URL as query strings.

Only redirect URLs to domains added as a platform on your Appwrite Console will be accepted. URLs not added as a platform are rejected to protect against redirect attacks.

```client-web
import { Client, Account } from "appwrite";

const client = new Client()
    .setProject('<PROJECT_ID>'); // Your project ID

const promise = account.createRecovery({
    email: 'email@example.com',
    url: 'https://example.com/recovery'
});

promise.then(function (response) {
    console.log(response); // Success
}, function (error) {
    console.log(error); // Failure
});
```

After receiving an email with the secret attached to the redirect link, submit a request to the Create Password Recovery (confirmation) endpoint to complete the recovery flow. The verification link sent to the user's email address is valid for 1 hour.

```client-web
import { Client, Account } from "appwrite";

const client = new Client()
    .setProject('<PROJECT_ID>'); // Your project ID

const promise = account.updateRecovery({
    userId: '<USER_ID>',
    secret: '<SECRET>',
    password: 'password'
});

promise.then(function (response) {
    console.log(response); // Success
}, function (error) {
    console.log(error); // Failure
});
```

# Security {% #security %}

Appwrite's security first mindset goes beyond a securely implemented authentication API. You can enable features like password strength requirements, password dictionary, password history, and disallow personal data in passwords to encourage users to pick better passwords. By enabling these features, you protect user data and teach better password choices, which helps make the internet a safer place.
