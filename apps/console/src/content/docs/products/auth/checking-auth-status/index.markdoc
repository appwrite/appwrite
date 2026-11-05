---
layout: article
title: Checking auth status
description: Learn how to check a user's authentication status in your Appwrite application and handle authentication flow appropriately.
---

One of the first things your application needs to do when starting up is to check if the user is authenticated. This is an important step in creating a great user experience, as it determines whether to show login screens or protected content.

# Check auth with `account.get()`

The recommended approach for checking authentication status is to use the `account.get()` method when your application starts:

{% multicode %}
```client-web
import { Client, Account } from "appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

const account = new Account(client);

// Check if user is logged in
async function checkAuthStatus() {
    try {
        // If successful, user is authenticated
        const user = await account.get();
        console.log("User is authenticated:", user);
        // Proceed with your authenticated app flow
        return user;
    } catch (error) {
        console.error("User is not authenticated:", error);
        // Redirect to login page or show login UI
        // window.location.href = '/login';
        return null;
    }
}

// Call this function when your app initializes
checkAuthStatus();
```
```client-flutter
import 'package:appwrite/appwrite.dart';

void checkAuthStatus() async {
  final client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

  final account = Account(client);

  try {
    // If successful, user is authenticated
    final user = await account.get();
    print('User is authenticated: ${user.name}');
    // Proceed with your authenticated app flow
  } catch (e) {
    print('User is not authenticated: $e');
    // Redirect to login page or show login UI
  }
}

// Call this function when your app initializes
```
```client-android-kotlin
import io.appwrite.Client
import io.appwrite.services.Account
import io.appwrite.exceptions.AppwriteException

class AuthManager {
    private val client = Client(context)
        .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
        .setProject("<PROJECT_ID>")

    private val account = Account(client)

    suspend fun checkAuthStatus(): Boolean {
        return try {
            val user = account.get()
            Log.d("Auth", "User is authenticated: ${user.name}")
            // Proceed with your authenticated app flow
            true
        } catch (e: AppwriteException) {
            Log.e("Auth", "User is not authenticated: ${e.message}")
            // Redirect to login page or show login UI
            false
        }
    }
}

// Call this function when your app initializes
```
```client-apple
import Appwrite

func checkAuthStatus() {
    let client = Client()
        .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
        .setProject("<PROJECT_ID>")

    let account = Account(client)

    Task {
        do {
            // If successful, user is authenticated
            let user = try await account.get()
            print("User is authenticated: \(user.name)")
            // Proceed with your authenticated app flow
        } catch {
            print("User is not authenticated: \(error)")
            // Redirect to login page or show login UI
        }
    }
}

// Call this function when your app initializes
```
{% /multicode %}

# Missing scope error

When a user is not authenticated and you call `account.get()`, you might see an error message like:

```
User (role: guests) missing scope (account)
```

This error is telling you that:
1. The current user has the role of "guest" (unauthenticated visitor)
2. This guest user does not have the required permission scope to access account information
3. This is the expected behavior when a user is not logged in

{% info title="Authentication flow" %}
In a typical application flow:

1. Call `account.get()` when your app starts
2. If successful → User is authenticated → Show the main app UI
3. If error → User is not authenticated → Redirect to login screen
{% /info %}

# Best practices

- Call `account.get()` early in your application lifecycle
- Handle both authenticated and unauthenticated states gracefully
- Show appropriate loading states while checking authentication
- Implement proper error handling to avoid showing error messages to users
