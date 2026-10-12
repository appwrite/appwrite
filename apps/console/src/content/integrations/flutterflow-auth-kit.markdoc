---
layout: integration
title: Auth Kit for FlutterFlow
description: Integrate Appwrite Auth into your FlutterFlow apps without writing complex code
date: 2024-03-01
featured: false
isPartner: true
isNew: true
cover: /images/integrations/flutterflow-auth-kit/cover.avif
category: auth
product:
  avatar: '/images/integrations/avatars/flutterflow.avif'
  vendor: FlutterFlow
  description: 'FlutterFlow is a low-code platform that lets you visually build and deploy mobile and web apps using Flutter.'
platform: 
    - 'Self-hosted'
    - 'Cloud'
images:
  - /images/integrations/flutterflow-auth-kit/cover.avif
  - /images/integrations/flutterflow-auth-kit/featured.avif
  - /images/integrations/flutterflow-auth-kit/auth-login-overview.avif
  - /images/integrations/flutterflow-auth-kit/login-pointers.avif
  - /images/integrations/flutterflow-auth-kit/dashboard-pointers.avif
---

FlutterFlow is a powerful visual builder that lets you create beautiful Flutter apps without writing much code. It's perfect for both beginners and experienced developers who want to build apps quickly. With its drag-and-drop interface and pre-built components, you can focus on creating great user experiences.

# What you’ll build

As you start creating your FlutterFlow app, you might want to add user accounts for things like social media features or task management. To help you with this, we've made it super easy to implement secure authentication using our official [Authentication library](https://marketplace.flutterflow.io/item/h1gn6StcXy6imjg7Ykr2). This guide will walk you through everything you need to know to get up and running with Appwrite authentication in your FlutterFlow project.

By the end of this guide, you’ll have a fully functional authentication system with:

- Email/password sign-up and login.
- Secure session management.
- User profile handling.
- Error handling with user feedback.
- Protected routes for authenticated users.

Want to see the final result first? Check out our [Appwrite Flutterflow Demo App](https://app.flutterflow.io/project/appwrite-auth-yxmd9b) to experience all these features in action! Then follow along to build your own custom version.

![Welcome screen](/images/integrations/flutterflow-auth-kit/welcomescreen.avif)

# Before you start

Here’s what you’ll need to have ready:
- [Appwrite account](https://cloud.appwrite.io/console/register).
- [FlutterFlow account](https://app.flutterflow.io/create-account).
- [FlutterFlow Marketplace account](https://marketplace.flutterflow.io/login) (must use the same email as your FlutterFlow account).
- Basic understanding of FlutterFlow’s interface.

## Step 1: Setting up your Appwrite project

Let’s start by setting up your Appwrite project (you can use a new or an existing one):

1. Head over to the [Appwrite Console](https://cloud.appwrite.io/).
2. Select your project (or create a new one).
3. Under **Add a platform**, add a **Flutter** platform.
4. In the platform setup, choose the platforms you want to support under Flutter App - Web, Android, and/or iOS - and enter the required values as shown below:


| Platform | Required Info |
|----------|----------------|
| Web      | Hostnames: `localhost`, `*.web.app`, `*.run.app` (Used for FlutterFlow Web Testing) |
| Android  | Package Name (e.g. `com.company.appname`) |
| iOS      | Bundle ID (e.g. `com.company.appname`) |

5. Go to **Settings > Overview** to copy the following values:
- **API Endpoint**
- **Project ID**

📌 You’ll need these in the next step to configure the authentication library.


## Step 2: Adding the authentication library

The Authentication library provides essential core functionality:

- Pre-configured Custom Actions for authentication.
- App States for session management.
- Built-in Error Handling.
- User Session Management.
- Custom Data Types for type-safe responses.

### Here’s how to add it to your project:

1. Visit the [FlutterFlow Marketplace](https://marketplace.flutterflow.io/).
2. Search for “Appwrite Authentication Kit” or visit [Appwrite Authentication Kit](https://marketplace.flutterflow.io/item/h1gn6StcXy6imjg7Ykr2).
3. Click “Add” to add it to your marketplace account.
4. In your FlutterFlow project:

   - Navigate to `Project Settings > Project Dependencies`.
   - Find “Appwrite Authentication Kit” under FlutterFlow Libraries.
   - Click “Add Library”.

![Screenshot of login](/images/integrations/flutterflow-auth-kit/screenshot.avif)

### Configure the Authentication Library

Once the library is added, you can configure it without writing any code:

1. Navigate to `Settings > Project Dependencies` in your FlutterFlow project.
2. Under the **Appwrite Authentication Kit** entry, click on **View Details**.
3. Update the environment values directly in the panel that opens:
   
- API Endpoint: `Your Appwrite Project Endpoint`
- Project ID: `Your Appwrite Project ID`

Note: This view opens automatically the first time a user adds the library - making it super easy to update values right away.


## Step 3: Understanding the components

The library sets up two essential app states:
- `appwriteConfig`: Stores configuration details securely.
- `appwriteUser`: Manages user session information.

Both states are automatically configured with:

- String data type.
- Persistence enabled.
- Authentication flow readiness.

### Custom Data Types:

The library provides two custom data types for type-safe responses:

1. `AppwriteUser`: Represents user data.  
   - Fields: id, email, name, emailVerified, status.  
   - Used for: Storing and passing user information.  

2. `AppwriteUserResponse`: Standard response format.  
   - Fields: success, error, errorCode, errorType, formattedError, user.  
   - Used for: Consistent error handling and success responses.

### Authentication actions:

The library provides five essential custom actions:

1. `initialize`  
   - Purpose: Sets up your Appwrite configuration.  
   - Returns: `AppwriteUserResponse` with initialization status.  
   - Must be called before any other authentication action.  

2. `signUpWithEmailAndPassword`  
   - Parameters: email, password.  
   - Returns: `AppwriteUserResponse` with user data.  
   - Handles: Account creation and session setup.  

3. `signInWithEmail`  
   - Parameters: email, password.  
   - Returns: `AppwriteUserResponse` with session data.  
   - Manages: User login process.  

4. `signOut`  
   - No parameters required.  
   - Returns: `AppwriteUserResponse` with success status.  
   - Handles: Complete session cleanup.  

5. `getCurrentUser`  
   - No parameters required.  
   - Returns: `AppwriteUserResponse` with current user data.  
   - Perfect for: Authentication state checks.

### Understanding action flows:

Each authentication action follows this consistent pattern:

1. Action execution.  
2. Response handling using `AppwriteUserResponse`.  
3. Success/failure conditions based on `response.success`.  
4. Error handling using `response.formattedError`.

![Flow example](/images/integrations/flutterflow-auth-kit/flow.avif)


### Example: Sign Up flow

1. Use the `signUpWithEmailAndPassword` action.  
2. Check `signUpResult.success`.  
3. Success? → Dashboard.  
4. Failure? → Show `signUpResult.formattedError`.

You can use `response.error` for internal logging and `response.formattedError` to display user-friendly messages. Similarly, you can implement flow patterns for other authentication actions (`signInWithEmail`, `signOut`, `getCurrentUser`), following the similar structure of checking results and handling success/failure scenarios appropriately.

## See it in action

Check out our [Appwrite FlutterFlow Demo App](https://app.flutterflow.io/project/appwrite-auth-yxmd9b) to explore the complete auth flow in action - from sign-up to session management.


## Troubleshooting common issues

1. **Library Not Found**  
   - Verify marketplace account email matches FlutterFlow account.  
   - Check if library is properly added in Project Dependencies.  

2. **Authentication Failures**  
   - Confirm `initialize` action is called first.  
   - Verify endpoint and projectId values.  
   - Check Appwrite console for platform settings.  

3. **Session Management Issues**  
   - Ensure app states are properly configured.  
   - Verify persistence settings.

This completes our guide on setting up Appwrite authentication in your FlutterFlow app. You now have a solid foundation for managing user accounts and secure sessions.

Now that you have the basics in place, you can enhance your app by implementing more advanced authentication features using Appwrite. If you run into any issues or have questions, the [Appwrite community on Discord](https://appwrite.io/discord) and the [FlutterFlow Community](https://community.flutterflow.io/) are always ready to help. Don't hesitate to reach out!

## More resources

If you would like to learn more about Appwrite and FlutterFlow, we have some resources that you should visit:

- [Appwrite Flutterflow Demo App](https://app.flutterflow.io/project/appwrite-auth-yxmd9b)
- [Appwrite Documentation](https://appwrite.io/docs)
- [FlutterFlow Marketplace](https://marketplace.flutterflow.io/item/h1gn6StcXy6imjg7Ykr2)
