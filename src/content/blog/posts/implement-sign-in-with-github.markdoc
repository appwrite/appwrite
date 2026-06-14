---
layout: post
title: How to implement Sign in with GitHub
description: In this tutorial, you'll learn how to create a fully functional GitHub login system in your app.
date: 2024-10-11
cover: /images/blog/implement-sign-in-with-github/cover.avif
timeToRead: 10
author: ebenezer-don
category: tutorial
faqs:
  - question: "How does GitHub OAuth work with Appwrite?"
    answer: "You register an OAuth app on GitHub, paste the client ID and secret into the GitHub provider in [Appwrite Auth](/docs/products/auth), and call the OAuth2 session endpoint from your client. Appwrite handles the token exchange and creates a session for the user."
  - question: "What callback URL should I use for GitHub OAuth?"
    answer: "Use the callback URL that Appwrite generates when you enable the GitHub provider, not a URL from your own app. Appwrite handles the token exchange and then redirects the user back to a success URL that you specify."
  - question: "Can I access the user's GitHub profile data after login?"
    answer: "Yes. Once the session is created, you can call account.get() to read the user's identity. To access GitHub APIs directly, request the provider access token via the account identities endpoint."
  - question: "Do I need a backend to implement Sign in with GitHub?"
    answer: "No. The flow described in the post uses only the client SDK, since Appwrite handles the OAuth exchange server side on your behalf. You only need a backend if you want to call GitHub APIs from a trusted environment."
  - question: "How do I add more OAuth providers later?"
    answer: "Each provider is a separate entry in the Auth settings of your Appwrite project. The client side flow is identical, so you can wire up Google, Apple, or Discord with only minimal changes to your code."
  - question: "What scopes does Appwrite request from GitHub by default?"
    answer: "By default, Appwrite requests the user's basic profile and email. You can pass additional scopes (for example, repo or read:org) when initiating the OAuth2 session if your app needs broader access."
---

If you're building an app that developers will use, adding GitHub login makes things easier for your users. In this tutorial, you'll learn how to create a GitHub login system using Vanilla JavaScript and Appwrite. By the end, your users will be able to log in with their GitHub accounts, see personalized details, and log out.

We'll go step by step, starting with setting up OAuth on GitHub, configuring Appwrite, and then connecting everything in your app. Even if you've never worked with OAuth or Appwrite before, at the end of this tutorial, you'll have a fully functional login system that you can easily integrate into any project.

![Appwrite GitHub auth demo](/images/blog/implement-sign-in-with-github/appwrite-github-auth.gif)

Let's get started!

 # **Prerequisites**

Before we begin, ensure you have the following:

- [Node.js](https://nodejs.org/) and [npm](https://www.npmjs.com/) installed
- A [GitHub account](https://github.com/)
- An [Appwrite account](https://console.appwrite.io/)

 # **Step 1: Setting up the project**

We'll begin by creating a new [Vite](https://vitejs.dev/) project and installing the necessary dependencies for Appwrite. Vite is the build tool we'll use to manage the project and its dependencies.

 ## **1.1. Create a Vite project**

In your terminal, navigate to the directory where you want to create your project and run the following commands:

```bash
npm create vite@latest github-auth-app --template vanilla
cd github-auth-app
npm install
```

These commands will create a new project folder called `github-auth-app` with the Vite vanilla JavaScript template. You'll also install the necessary dependencies for Vite.

Next, you can test the project by running `npm run dev` and opening the specified URL that Vite will provide. You should see the default Vite app for Vanilla JS.

 ## **1.2. Install the Appwrite SDK**

Now, we need to install the [Appwrite SDK](https://appwrite.io/docs/sdks), which will allow us to interact with Appwrite's authentication and database services.

Run this command inside your project folder:

```bash
npm install appwrite
```

This will add the Appwrite JavaScript SDK to your project, which we'll use later to handle GitHub OAuth authentication.

 # **Step 2: Setting up GitHub OAuth**

We need to set up an OAuth application on GitHub to enable authentication for users using their GitHub accounts.

 ## **2.1. Create a new OAuth app on GitHub**

1. Go to your GitHub account.
2. Navigate to **Settings** > **Developer settings**.
3. Click on **OAuth Apps**.
4. Click **New OAuth App**.

You'll see a form titled **Register a new OAuth app**. This is where you'll fill in the details of your GitHub OAuth app:

![GitHub OAuth app setup](/images/blog/implement-sign-in-with-github/image-1.avif)

- **Application Name**: You can name it "GitHub Auth with Appwrite".
- **Homepage URL**: Input your homepage URL. You can use your localhost URL for now.
- **Authorization callback URL**: You can use the same URL as your homepage URL for now. We'll update this later with a value generated by Appwrite.

Once you've filled out the form, click **Register Application**. GitHub will provide you with a **Client ID** and **Client Secret**. **Save these details** because we'll need them when configuring Appwrite.

![GitHub OAuth app details](/images/blog/implement-sign-in-with-github/image-2.avif)

 # **Step 3: Setting up Appwrite**

Now that we have our GitHub OAuth app set up, let's move on to configuring Appwrite. Appwrite will handle the OAuth process and act as the backend for our authentication flow.

 ## **3.1. Create a new Appwrite project**

If you already have an Appwrite project, you can skip this step.

1. Log in to your Appwrite console.
2. Click on **Projects** and then **+ Create Project**.
3. Give the project a name and click **Create**.

Take note of the **Project ID** and **API Endpoint** for this project, as we will need these later when configuring our frontend. You can find these in the **Settings** page of your Appwrite project.

![Appwrite project settings](/images/blog/implement-sign-in-with-github/image-3.avif)

 ## **3.2. Add GitHub as an authentication provider**

1. Inside your Appwrite project, go to the **Auth** section from the left-hand menu.
2. Click **Settings** and scroll down to the **OAuth2 Providers** section
3. Select **GitHub** from the list of providers.
4. Enter the **Client ID** and **Client Secret** that you obtained from GitHub earlier.
5. At the bottom of the form, you'll see a URI field. This is the **Authorization Callback URL** that you'll need for GitHub.

![Appwrite GitHub OAuth settings](/images/blog/implement-sign-in-with-github/image-4.avif)

You need to copy this URL and **update the callback URL** in your GitHub OAuth app (from **Step 2.1**) to this new value.

With this, Appwrite will be configured to handle GitHub OAuth.

 # **Step 4: Configuring the frontend project**

Next, let's set up our project to communicate with Appwrite using the Client ID and API details from Appwrite.

 ## **4.1. Create an `.env` file**

In the root of your project, create a file called `.env` and add the following environment variables:

```bash
VITE_APPWRITE_ENDPOINT=[your_appwrite_endpoint]
VITE_APPWRITE_PROJECT_ID=[your_appwrite_project_id]
```

Replace `[your_appwrite_endpoint]` and `[your_appwrite_project_id]` with the actual values from your Appwrite project.

These environment variables will be used by Vite and Appwrite to authenticate users and make API calls.

 # **Step 5: Building the app**

Now let's start writing the code to handle the GitHub OAuth functionality.

 ## **5.1. Setting up the HTML**

We'll start by setting up the HTML structure of our app.

Open the `index.html` file inside the `public` folder and replace its contents with this:

```html
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>GitHub Auth with Appwrite</title>
  </head>
  <body>
    <div id="app"></div>
    <script type="module" src="/main.js"></script>
  </body>
</html>
```

Here, we have a simple HTML file with an empty `<div>` element (with the ID as `app`) where our JavaScript code will dynamically insert the login buttons or user information.

 ## **5.2. Initializing the Appwrite client**

Next, let's set up the Appwrite client and handle authentication.

Open `main.js` and replace what's currently in it. We'll start by initializing the Appwrite client. Add this code to your `main.js` file:

```jsx
/* Import the required Appwrite modules */
import { Client, Account, OAuthProvider } from 'appwrite'

/* Initialize the Appwrite client */
const client = new Client()
client
  .setEndpoint(import.meta.env.VITE_APPWRITE_ENDPOINT)
  .setProject(import.meta.env.VITE_APPWRITE_PROJECT_ID)

/* Create an instance of the Account service */
const account = new Account(client)

```

This block of code initializes the Appwrite client with the endpoint and project ID from our `.env` file. The `Account` service will allow us to manage the user's authentication.

 ## **5.3. Handling user authentication**

Now that we have the Appwrite client set up, let's handle user authentication using GitHub OAuth. We'll first add code to check if the user is already authenticated.

Add the following block to your `main.js` file:

```jsx
/* Declare a variable to store the user */
let user = null

/* Function to check if a user is already logged in */
const checkSession = async () => {
  try {
    user = await account.get()
    updateUI()
  } catch (error) {
    if (error.code === 401) {
			/* User is not logged in */
      user = null
    } else {
      console.error('Session error:', error)
    }
    updateUI()
  }
}

```

Let's break down what's happening in the code:

1. **`account.get()`**: This function retrieves the current user session. If the user is logged in, it returns their details. If not, an error is thrown.
2. **Session Error Handling**: If the error code is `401`, it means the user isn't logged in. We handle this by setting `user` to `null`.

Next, we'll update the UI based on whether the user is logged in or not.

 ## **5.4. Updating the UI based on authentication state**

Now, let's create the `updateUI` function that will render different content based on whether the user is logged in or logged out.

Add this code next:

```jsx
/* Get the app element from the DOM */
const app = document.querySelector('#app')

/* Function to update the UI based on the user's authentication state */
const updateUI = () => {
  if (user) {
/* If the user is logged in, show a welcome message and logout button */
    app.innerHTML = `
      <h1>Welcome, ${user.name}!</h1>
      <button id="logout">Logout</button>
    `
    document.querySelector('#logout').addEventListener('click', logout)
  } else {
/* If the user is logged out, show a login button */
    app.innerHTML = `
      <h1>GitHub x Appwrite</h1>
      <button id="login">Login with GitHub</button>
    `
    document.querySelector('#login').addEventListener('click', login)
  }
}
```

In this code:

- **Logged-in State**: If the user is logged in, the app displays a welcome message and a **Logout** button.
- **Logged-out State**: If the user is not logged in, the app displays a **Login with GitHub** button.

 ## **5.5. Implementing the login and logout functionality**

Finally, let's implement the `login` and `logout` functions to handle the OAuth process.

Add the following code to your `main.js` file:

```jsx
/* Function to log in using GitHub OAuth */
const login = async () => {
  try {
    await account.createOAuth2Session({
      provider: OAuthProvider.Github,
      success: 'http://localhost:5174/',// Redirect here on success
      failure: 'http://localhost:5174/',// Redirect here on failure
    })
  } catch (error) {
    console.error('Login error:', error)
  }
}

/* Function to log out */
const logout = async () => {
  try {
    await account.deleteSession({ sessionId: 'current' }) // Delete the current session
    user = null
    updateUI()
  } catch (error) {
    console.error('Logout error:', error)
  }
}

/* Check if user is logged in when the app loads */
checkSession()

```

Let's look at the functions we've added:

1. **`login()`**: This function creates an OAuth session with GitHub. If the login is successful, the user will be redirected back to the app.
2. **`logout()`**: This function logs the user out by deleting the current session in Appwrite.

We also call `checkSession()` when the app loads to check if the user is already authenticated.

Don't forget to change the redirect URLs in the `login()` function to your app's URL.

 # **Step 6: Running and testing the app**

Now that everything is set up, let's run the app and test it.

 ## **Start the development server**

If your app isn't already up, start it by running the following command on your terminal:

```bash
npm run dev
```

This will start the development server. Open your browser and go to the URL displayed in your terminal. You should see the login screen with a **Login with GitHub** button.

 ## **Test the authentication flow**

- Click the **Login with GitHub** button. This will redirect you to GitHub for authentication.
- After logging in, you'll be redirected back to the app, and you should see a welcome message with your GitHub username.
- Click the **Logout** button to end the session and return to the login screen.

![Appwrite GitHub auth demo](/images/blog/implement-sign-in-with-github/appwrite-github-auth.gif)

 # **Conclusion**

You've just built a simple GitHub authentication app using **Vite**, **Vanilla JavaScript**, and **Appwrite**. Throughout the tutorial, we incrementally built the app, and you learned how to:

- Set up OAuth2 authentication with GitHub.
- Use Appwrite to handle authentication.
- Manage a simple UI for login and logout.

From here, you can expand the app by integrating additional GitHub APIs or adding features such as fetching repositories or displaying more user information. You can even add a database using [Appwrite](https://appwrite.io/docs/products/databases/quick-start) to store user information.

 # Further reading

- [How to set up Google authentication in React with Appwrite](https://appwrite.io/blog/post/set-up-google-auth-appwrite-react)
- [Integrate any external authentication solution into your Appwrite project](https://appwrite.io/blog/post/integrate-custom-auth-sveltekit)
- [Appwrite auth documentation](https://appwrite.io/docs/products/auth)