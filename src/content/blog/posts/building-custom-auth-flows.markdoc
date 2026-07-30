---
layout: post
title: Building custom authentication flows with Appwrite
description: Learn how to integrate custom authentication flows with Appwrite using custom tokens.
date: 2025-01-14
cover: /images/blog/building-custom-auth-flows/cover.avif
timeToRead: 11
author: ebenezer-don
category: tutorial
lastUpdated: 2026-06-29
faqs:
  - question: "What exactly are custom authentication flows?"
    answer: "They're login systems you build yourself instead of relying on standard providers like Google or Firebase. You decide how users are verified, how tokens or sessions are issued, and how identity data is stored. It's useful when your app needs unique logic or you already manage users elsewhere."
  - question: "When does it make sense to build a custom auth flow?"
    answer: "Mostly when existing solutions don't fit, for example, you have a legacy user database, need to integrate with an enterprise SSO, or want to control every step of the login process. If your app's auth logic is straightforward, built-in options are faster; if it's not, custom flows give you freedom."
  - question: "What's the most common mistake when building custom auth?"
    answer: "Mixing frontend and backend responsibilities. Token creation and validation should always happen on the server. Another one is skipping input validation or leaving long-lived tokens active. Small security gaps in auth often turn into big problems later."
  - question: "Can I combine custom auth with other login methods like OAuth or magic links?"
    answer: "Yes. It's actually common to offer multiple login options, say, Google or GitHub for convenience and your own system for internal or enterprise users. You can unify them under the same session layer once they're verified."
  - question: "Can I create custom authentication flows on Appwrite?"
    answer: "Yes. Appwrite lets you build your own auth flow using custom tokens. That means your backend can verify users however you want, through an existing database, SSO, or a third-party system, and then generate a token that Appwrite uses to create a secure session. You get full control over user validation while Appwrite handles session management, permissions, and APIs."
---

While Appwrite provides built-in authentication methods like email/password, OAuth, and Magic URL, there are scenarios where you need more flexibility.
You might have an existing user database, a legacy authentication system that needs to be maintained, or specific security requirements that demand custom implementation.

Appwrite's custom authentication solves these challenges by allowing you to integrate your existing authentication system or third-party identity providers.
You can validate users through your system while still benefiting from Appwrite's session management and user features.

In this guide, you'll learn how to implement custom authentication flows using Appwrite's custom tokens.
We'll cover validating users through an external system, generating custom tokens, creating secure sessions, and managing the complete authentication lifecycle.

# What we'll build

A simple authentication demo that:

- Uses a simulated third-party authentication system
- Integrates with Appwrite's custom token authentication
- Provides a login/logout flow with session management

# Setting up your project

Before diving into the code, let's ensure you have the necessary prerequisites in place.
Start by verifying your Node.js installation in your local environment:

```bash
node --version
```

Next, you'll need to set up your Appwrite project. Head over to the [Appwrite Console](https://cloud.appwrite.io/) and either create a new project or open an existing one.
Make sure to note down your Project ID, which you can find in the Settings page.

To enable custom authentication, you'll need an API key with the appropriate permissions.
In your project's overview page, navigate to the **Integrations** section and click on **API Keys**.
Create a new API key with a suitable name (e.g., "Custom Auth") and optionally set an expiry date.
When configuring permissions, ensure you select the **Auth** scope. After creation, securely save your API key as you'll need it in the next steps.

# Project setup

Let's start by creating a new Vite project. We'll use vanilla JavaScript for this tutorial to keep things simple and focused:

```bash
npm create vite@latest . -- --template vanilla
```

Our project will require several dependencies to handle both frontend and backend functionality. Install them using npm:

```bash
npm install appwrite cors dotenv express node-appwrite
```

Now, let's set up our environment configuration. Create a `.env` file in your project root to store your Appwrite credentials and other important variables:

```
VITE_APPWRITE_ENDPOINT=https://cloud.appwrite.io/v1
VITE_APPWRITE_PROJECT_ID=your_project_id
VITE_BACKEND_URL=http://localhost:3000

# Server-only variables
APPWRITE_API_KEY=your_api_key
APPWRITE_ENDPOINT=https://cloud.appwrite.io/v1
APPWRITE_PROJECT_ID=your_project_id
```

To properly configure our project for modern JavaScript modules and add convenient scripts, update your `package.json`:

```json
{
  "type": "module",
  "scripts": {
    "dev": "vite",
    "server": "node server.js",
    "build": "vite build",
    "preview": "vite preview"
  }
}
```

# Backend implementation

The backend server will handle user validation and generate Appwrite custom tokens.
Create a new file named `server.js` in your project root. Let's break down the implementation into logical sections.

First, we'll set up our basic server infrastructure by importing dependencies and loading environment variables:

```js
import express from 'express'
import cors from 'cors'
import { Client, Users } from 'node-appwrite'
import dotenv from 'dotenv'

// Load environment variables
dotenv.config()
```

Before proceeding, we should validate that all required environment variables are present.
This helps catch configuration issues early:

```js
// Validate required environment variables
const requiredEnvVars = [
  'APPWRITE_ENDPOINT',
  'APPWRITE_PROJECT_ID',
  'APPWRITE_API_KEY',
]
for (const envVar of requiredEnvVars) {
  if (!process.env[envVar]) {
    console.error(`Missing required environment variable: ${envVar}`)
    process.exit(1)
  }
}
```

With our environment validated, we can set up the Express server and configure necessary middleware:

```js
const app = express()
app.use(cors())
app.use(express.json())
```

Now we'll initialize the Appwrite client with our configuration:

```js
// Initialize Appwrite
const client = new Client()
  .setEndpoint(process.env.APPWRITE_ENDPOINT)
  .setProject(process.env.APPWRITE_PROJECT_ID)
  .setKey(process.env.APPWRITE_API_KEY)

const users = new Users(client)
```

For demonstration purposes, we'll create a simulated user database.
In a real application, this would be replaced with your actual user database or authentication system:

```js
// Simulate a third-party auth database
const thirdPartyUsers = {
  'demo@example.com': {
    password: 'demo1234',
    name: 'Demo User',
    id: 'external_123',
  },
}
```

{% info title="Security Note" %}
This simulation uses plain text passwords for simplicity. In a production environment, always use secure password hashing and proper security measures.
{% /info %}

Let's implement the login endpoint that will handle authentication requests:

```js
// Simulate third-party login
app.post('/auth/external/login', async (req, res) => {
  const { email, password } = req.body

  // Simulate external auth validation
  const user = thirdPartyUsers[email]
  if (!user || user.password !== password) {
    return res.status(401).json({ message: 'Invalid credentials' })
  }

  try {
    // Check if user exists in Appwrite
    try {
      await users.get(user.id)
    } catch {
      // User doesn't exist, create them
      await users.create(
        {
          userId: user.id,
          email: email,
          name: user.name
      },
      )
    }

    // Create Appwrite token and return it to the client
    await users.get({ userId: user.id })
    await users.create({
      userId: user.id,
      email,
      phone: undefined,
      password: undefined, // can remain undefined for custom auth
      name: user.name,
    })
    const token = await users.createToken({
      userId: user.id
    })
    res.json({
      userId: user.id,
      secret: token.secret,
      name: user.name,
    })
  } catch (error) {
    res.status(500).json({ message: error.message })
  }
})
```

This endpoint handles several important tasks:

- Validates user credentials against our simulated database
- Creates the user in Appwrite if they don't already exist
- Generates a custom token for the authenticated user
- Returns the token and user information to the client

Finally, let's start the server on our specified port:

```js
const PORT = process.env.PORT || 3000

app.listen(PORT, () => {
  console.log(`Server running on http://localhost:${PORT}`)
})
```

With the server implementation complete, you can start it using:

```bash
npm run server
```

# Frontend implementation

Now that our authentication server is running, let's create an intuitive user interface.
We'll break this down into several parts: HTML structure, styling, and JavaScript logic.

## HTML structure and styling

The foundation of our frontend starts with a clean HTML structure.
We'll create a simple container-based layout that will house our authentication components.
In your `index.html` file, add the following code:

```html
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <link rel="icon" type="image/svg+xml" href="/vite.svg" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Appwrite Custom Auth Demo</title>
  </head>
  <body>
    <div class="container">
      <!-- Content will go here -->
    </div>
    <script type="module" src="/src/main.js"></script>
  </body>
</html>
```

To ensure our interface is easy to use, we'll add some CSS styles:

```html
<style>
  .container {
    max-width: 400px;
    margin: 50px auto;
    padding: 20px;
    border: 1px solid #ccc;
    border-radius: 8px;
  }
  .form-group {
    margin-bottom: 15px;
  }
  input {
    width: 100%;
    padding: 8px;
    margin-top: 5px;
  }
  button {
    width: 100%;
    padding: 10px;
    background: #4caf50;
    color: white;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    margin-bottom: 10px;
  }
  .info {
    background: #f0f0f0;
    padding: 15px;
    border-radius: 4px;
    margin-bottom: 20px;
  }
  .hidden {
    display: none;
  }
</style>
```

To help users understand what the demo does, we'll add an informative section at the top which will include the test credentials:

```html
<div class="info">
  <h3>Custom Token Auth Demo</h3>
  <p>
    This demonstrates using Appwrite's custom token authentication with a
    simulated third-party auth system.
  </p>
  <p>Try: demo@example.com / demo1234</p>
</div>
```

The main authentication interface consists of two views: the login form and the logged-in state.
First, let's create the login form with proper input validation:

```html
<!-- External Login Form -->
<div id="loginForm">
  <h2>External Auth System</h2>
  <div class="form-group">
    <label for="email">Email:</label>
    <input type="email" id="email" required />
  </div>
  <div class="form-group">
    <label for="password">Password:</label>
    <input type="password" id="password" required />
  </div>
  <button id="loginButton">Login with External System</button>
</div>
```

We also need a view for when the user is successfully authenticated.
This view will display the user's information and provide a logout option:

```html
<!-- Logged In View -->
<div id="loggedInView" class="hidden">
  <h2>Welcome!</h2>
  <p id="userInfo"></p>
  <button id="logoutButton">Logout</button>
</div>
```

## JavaScript implementation

Now let's implement the frontend logic in `src/main.js`. We'll break this down into specific parts that work together to handle the authentication flow.

First, we set up the connection to Appwrite. This code tells our frontend how to talk to Appwrite's servers:

```js
import { Client, Account } from 'appwrite'

// Initialize Appwrite
const client = new Client()
  .setEndpoint(import.meta.env.VITE_APPWRITE_ENDPOINT)
  .setProject(import.meta.env.VITE_APPWRITE_PROJECT_ID)

const account = new Account(client)
```

We need quick access to our HTML elements to show/hide them and update their content.
These variables help us do that:

```js
// DOM Elements
const loginForm = document.getElementById('loginForm')
const loggedInView = document.getElementById('loggedInView')
const userInfo = document.getElementById('userInfo')
```

The `handleExternalAuth` function is the main piece of our login process.
It takes the user's email and password and does four specific things:

```js
// Handle external auth and Appwrite session creation
async function handleExternalAuth(email, password) {
  try {
    // First, authenticate with external system
    const response = await fetch(
      `${import.meta.env.VITE_BACKEND_URL}/auth/external/login`,
      {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({ email, password }),
      },
    )

    const data = await response.json()
    if (!response.ok) throw new Error(data.message)

    // Then create Appwrite session using the custom token
    const session = await account.createSession({
      userId: data.userId,
      secret: data.secret
    })
    await account.deleteSession({ sessionId: 'current' })

    // Show logged in state
    loginForm.classList.add('hidden')
    loggedInView.classList.remove('hidden')
    userInfo.textContent = `Logged in as: ${data.name}`

    return session
  } catch (error) {
    throw new Error('Authentication failed: ' + error.message)
  }
}
```

This function:

- Sends the login details to our backend
- Gets back a token if the login is successful
- Creates an Appwrite session with this token
- Shows the logged-in screen with the user's name

Next, we add click handlers to our login and logout buttons.
These functions run when users click the buttons:

```js
// Handle login button click
document.getElementById('loginButton').addEventListener('click', async () => {
  const email = document.getElementById('email').value
  const password = document.getElementById('password').value

  try {
    await handleExternalAuth(email, password)
  } catch (error) {
    alert(error.message)
  }
})

// Handle logout
document.getElementById('logoutButton').addEventListener('click', async () => {
  try {
    await account.deleteSession({
        sessionId: 'current'
    })
    loginForm.classList.remove('hidden')
    loggedInView.classList.add('hidden')
  } catch (error) {
    alert('Logout failed: ' + error.message)
  }
})
```

These click handlers:

- Get the email and password from the form
- Try to log the user in or out
- Show error messages if something goes wrong
- Switch between the login and logged-in screens

Lastly, we check if the user is already logged in when they load the page:

```js
// Check auth status on load
async function checkAuth() {
  try {
    const session = await account.get()
    loginForm.classList.add('hidden')
    loggedInView.classList.remove('hidden')
    userInfo.textContent = `Logged in as: ${session.name}`
  } catch {
    loginForm.classList.remove('hidden')
    loggedInView.classList.add('hidden')
  }
}

checkAuth()
```

This check is important because it:

- Looks for an existing login session
- Shows the logged-in screen if a session exists
- Shows the login form if no session is found

# Running the application

To run the application, you'll need to start both the backend and frontend servers.
First, start the backend server:

```bash
npm run server
```

Then, in a new terminal window, start the frontend development server:

```bash
npm run dev
```

Navigate to the URL shown by Vite (typically `http://localhost:5173`) in your browser.
You can test the authentication using these credentials:

- Email: `demo@example.com`
- Password: `demo1234`

# Putting it all together

Now that we have all the pieces in place, let's do a quick rundown of what happens when a user logs in.
Once a user submits their login details, their credentials go to our backend server.
The server checks these against our user database and, if they're valid, asks Appwrite to create a custom token.
This token comes back to the frontend along with user information.
Finally, the frontend uses this token to create an Appwrite session, which keeps the user logged in and lets them access protected resources.

# Next steps

To enhance this basic implementation, consider these improvements:

- Replace the simulated user database with your actual authentication system
- Add comprehensive error handling and input validation
- Implement user registration functionality
- Improve the UI with loading states and better error messages. You might want to use a UI framework or template engine depending on your project's requirements.

# Conclusion

This tutorial has demonstrated how to implement custom authentication with Appwrite. You've learned how to:

- Set up a custom authentication server integrated with Appwrite
- Generate and manage Appwrite custom tokens
- Create a frontend that handles the authentication flow
- Implement secure session management

While we've used a simple in-memory user database for demonstration, these concepts apply to any external authentication provider, whether it's your own user database or third-party identity providers.

Remember that authentication is an important security component.
Before deploying to production, ensure you've implemented proper security measures, error handling, and user management features.

The complete source code for this tutorial is available in our [GitHub repository](https://github.com/appwrite-community/appwrite-custom-auth).

# More resources

- [Appwrite Custom token login](https://appwrite.io/docs/products/auth/custom-token)
- [Appwrite Authentication docs](https://appwrite.io/docs/products/auth)
- [How to set up Google authentication in React with Appwrite](https://appwrite.io/blog/post/set-up-google-auth-appwrite-react)
