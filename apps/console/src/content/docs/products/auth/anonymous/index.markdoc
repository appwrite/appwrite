---
layout: article
title: Anonymous login
description: Manage user identities and profiles effectively with Appwrite. Dive into user management features, account settings, and user data customization.
---

Anonymous sessions allow you to implement **guest** users. Guest users let you store user information like items in their cart or theme preferences before they create an account. This reduces the friction for your users to get started with your app.

**If a user later creates an account**, their information will be inherited by the newly created account.

# Create anonymous session {% #createSession %}

Create an anonymous session with [Create Anonymous Session](/docs/references/cloud/client-web/account#createAnonymousSession) method.

{% multicode %}
```client-web
import { Client, Account } from "appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<PROJECT_ID>');                 // Your project ID

const account = new Account(client);

const promise = account.createAnonymousSession();

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
    .setProject('<PROJECT_ID>');                 // Your project ID

final account = Account(client);

final user = await account.createAnonymousSession();
```
```client-apple
import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<PROJECT_ID>")                  // Your project ID

let account = Account(client)

let user = try await account.createAnonymousSession()
```
```client-android-kotlin
import io.appwrite.Client
import io.appwrite.services.Account

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<PROJECT_ID>")                  // Your project ID

val account = Account(client)

val user = account.createAnonymousSession()
```
```graphql
mutation {
    accountCreateAnonymousSession {
        _id
        userId
        provider
        expire
    }
}
```
{% /multicode %}

# Attaching an account {% #attach-account %}

Anonymous users cannot sign back in. If the session expires, they move to another computer, or they clear their browser data, they won't be able to log in again. Remember to prompt the user to create an account to not lose their data.

Create an account with any of these methods to transition from an anonymous session to a user account session.

{% arrow_link href="/docs/products/auth/email-password" %}
Email and password
{% /arrow_link %}

{% arrow_link href="/docs/products/auth/phone-sms" %}
Phone (SMS)
{% /arrow_link %}

{% arrow_link href="/docs/products/auth/magic-url" %}
Magic URL
{% /arrow_link %}

{% arrow_link href="/docs/products/auth/oauth2" %}
OAuth2
{% /arrow_link %}
