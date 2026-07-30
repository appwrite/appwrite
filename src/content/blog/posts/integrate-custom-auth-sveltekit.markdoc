---
layout: post
title: Integrate any external authentication solution into your Appwrite project
description: Add any custom authentication flow to your applications.
date: 2024-05-30
lastUpdated: 2026-06-29
cover: /images/blog/integrate-custom-auth-sveltekit/cover.avif
timeToRead: 6
author: aditya-oberai
category: product
featured: false
faqs:
  - question: "What is custom token authentication in Appwrite?"
    answer: "Custom token authentication lets you use a Server SDK to generate a short lived token that the client SDK can exchange for a session. This way, you can code your own login flow (or wrap an external provider) while still issuing real Appwrite sessions. See [Appwrite Auth](/docs/products/auth) for details."
  - question: "When should I use custom tokens instead of built in providers?"
    answer: "Use custom tokens when you need to integrate a system Appwrite doesn't ship a first party provider for, like an internal SSO, a legacy database of users, or a specialized provider. For standard email, OAuth, or OTP flows the built in providers are simpler."
  - question: "Can I run the token generation inside an Appwrite Function?"
    answer: "Yes. [Appwrite Functions](/docs/products/functions) can host your custom auth logic and call the Server SDK to create the token. The client then exchanges that token for a session, which keeps your API key out of the browser."
  - question: "How long is a custom auth token valid for?"
    answer: "Custom tokens are short lived and intended to be exchanged for a session immediately after they are issued. Once exchanged, the session follows your project's normal session lifetime."
  - question: "Why does this tutorial use SvelteKit?"
    answer: "SvelteKit has built in server routes, so you can run the privileged token generation server side without spinning up a separate backend. The same pattern works in Next.js, Nuxt, Remix, or any framework with server side code."
  - question: "Is custom token auth secure if I expose my API key to the client?"
    answer: "Your API key must never reach the client. Token generation runs on a server (a SvelteKit endpoint, an Appwrite Function, or your own backend), and only the resulting token secret is returned to the browser to exchange for a session."
---

Whether we contribute to any existing software or build new one, user authentication is a fundamental feature our users need. Between email-password authentication, magic URLs, phone and email OTPs, and 30+ OAuth providers, Appwrite offers a variety of 1st-party offerings for your apps. However, every now and then, you will need an authentication solution beyond this list. Fortunately, Appwrite now offers a solution that allows developers to integrate any external authentication method with their Appwrite project.

Therefore, in this blog, we will learn about Appwrite’s custom token authentication solution, how it works, and how you can implement it in a SvelteKit app.

# What is custom token authentication?

Custom token authentication allows you to use one of Appwrite’s [Server SDKs](https://appwrite.io/docs/sdks#server) to generate tokens, short-lived secrets that can be exchanged for a session by a [Client SDK](https://appwrite.io/docs/sdks#client) to log in users. This allows you to code your own authentication methods using Appwrite Functions or your own server-side APIs. This can be beneficial in a number of scenarios, such as:

1. **Legacy system integration**: Integrate with old systems using unique authentication methods without major changes.
2. **Custom security needs**: Implement special security features like hardware tokens or voice recognition.
3. **External authentication providers**: Use providers like Clerk, SuperTokens, or Amazon Cognito, which Appwrite doesn’t support directly.
4. **Advanced user authentication**: Create more sophisticated auth workflows, for example, triggering different authentication methods based on the user's location, device, or behavior.
5. **Single Sign-On (SSO)**: Integrate with enterprise SSO solutions that use protocols like SAML or LDAP.
6. **Migration to Appwrite**: Transition smoothly from an existing authentication system to Appwrite.

# Implementing custom token authentication

In order to implement custom token authentication in an application, you need to develop two distinct parts:

1. Server-side function to run the authentication flow and create a user token
2. Client-side app to trigger the custom auth flow and create a session via the token secret

For the purposes of this demo application, I will be implementing these in a SvelteKit application.

## Pre-requisites

Before we implement our auth flow, we must first:

- Create an Appwrite project and create an API key
- Set up a SvelteKit app on our local system

### Appwrite

First, we must create an account on [Appwrite Cloud](https://cloud.appwrite.io/), followed by creating a new project and an API key with the scopes `users.read` and `users.write`.

![Appwrite Project Overview](/images/blog/integrate-custom-auth-sveltekit/overview.avif)

> Note: If you plan to deploy this application publicly, please also add the hostname of your web app as a Web platform to the project.

### SvelteKit

To build this app, we will use SvelteKit, a framework that lets you build web applications using JavaScript. For the purpose of this blog, we will use a server function in the SvelteKit app to create our custom auth flow. However, you can also use an Appwrite function or develop your own backend API if you prefer that.

We will first set up a skeleton SvelteKit project (without TypeScript):

```bash
npm create svelte@latest my-project
cd my-project
npm i
```

Next, we shall create a `.env` file at the root of the directory and add the following:

```bash
PUBLIC_APPWRITE_ENDPOINT=
PUBLIC_APPWRITE_PROJECT_ID=
APPWRITE_API_KEY=
```

## Creating the server function

To create our server function, which contains the custom auth flow and creates a token, we will first install the Appwrite Node.js SDK by running the following command in our terminal:

```bash
npm i node-appwrite
```

We will then develop our API route `/auth` by creating a file `./src/routes/auth/+server.js` and add the following code:

```js
import { Client, Users, ID, Query } from 'node-appwrite';
import { PUBLIC_APPWRITE_ENDPOINT, PUBLIC_APPWRITE_PROJECT_ID } from '$env/static/public'; // Gets the public environment variables shared with the client
import { env } from '$env/dynamic/private'; // Gets the private server-only environment variable

const endpoint = PUBLIC_APPWRITE_ENDPOINT;
const projectId = PUBLIC_APPWRITE_PROJECT_ID;
const apiKey = env.APPWRITE_API_KEY;

const client = new Client()
    .setEndpoint(endpoint)
    .setProject(projectId)
    .setKey(apiKey);

const users = new Users(client);

/**
 * Returns user if user exists in Appwrite, if not creates a new user
 *
 * @param {string} email
 * @returns {Promise<import("node-appwrite").Models.User>} user
 */
async function getUser(email) {
    try {
        let usersList = await users.list({
            queries: [ Query.equal('email', email) ]
        });
        if (usersList.total != 0) {
            return usersList.users[0];
        } else {
            return await users.create({
                userId: ID.unique(),
                email: email
            });
        }
    } catch (err) {
        console.error(err);
    }

}

/**
 * Logic for authentication
 *
 * @param {string} email
 * @param {string} password
 * @returns {Promise<import("node-appwrite").Models.User>} user
 */
async function authLogic(email, password) {
    try {
        // You can have any auth logic here. For this example, we're only matching the password with '123456'
        if (password === '123456') {
            return await getUser(email);
        } else {
            return null;
        }
    } catch (err) {
        console.error(err);
    }
}

export async function POST({ request }) {
    try {
        const requestBody = await request.json();
        const email = requestBody.email;
        const password = requestBody.password;

        // Call the auth logic
        let user = await authLogic(email, password);

        // If user exists, create a token
        if(user) {
            let token = await users.createToken({
                userId: user.$id
            });

            // Ideally, you should not send the token object in response. Send the token secret to the user through an alternative secure channel
            return new Response(JSON.stringify({ user, token }), { status: 200, headers: { 'Content-Type': 'application/json' } });
        } else {
            return new Response(JSON.stringify({ message: 'Invalid credentials' }), { status: 401, headers: { 'Content-Type': 'application/json' } });
        }
    } catch(err){
        console.error(err);
        return new Response(JSON.stringify({ message: err.message }), { status: 500, headers: { 'Content-Type': 'application/json' } });
    }
}
```

When a `POST` request is sent to this endpoint, the `POST` action uses the `authLogic` function (which can contain any custom authentication logic). On successful credentials verification, the function returns a user from Appwrite (or creates a new one) using the `getUser` function.

One highly important note for production applications is that unless the client is already trusted, you should not return the token object directly to the client application. Instead, the token secret should be sent to the user over a secure channel such as email or SMS.

## Developing the client app

Before we create our client app functionality, we will set up the Appwrite Web SDK. We will first install the SDK by running the following command in our terminal:

```bash
npm i appwrite
```

 We will then create a file `./src/lib/appwrite.js` and add the following code:

```js
import { Client, Account } from 'appwrite';
import { PUBLIC_APPWRITE_ENDPOINT, PUBLIC_APPWRITE_PROJECT_ID } from '$env/static/public';

const endpoint = PUBLIC_APPWRITE_ENDPOINT;
const projectId = PUBLIC_APPWRITE_PROJECT_ID;

const client = new Client()
	.setEndpoint(endpoint) // Your API Endpoint
	.setProject(projectId); // Your project ID;

export const account = new Account(client);
```

Next, we will develop a page at the index route of our demo app. As this is a demo app, the code will focus only on the application logic. All CSS or styling-related information will be accessible in the final project repository at the end of the blog.

We will create a file `./src/routes/+page.svelte` and add the following code:

```html
<script>
	import { account } from '$lib/appwrite';
	import { onMount } from 'svelte';

	let user = '';
	let session = '';
	let token = '';

	let state = ['notLoggedIn', 'tokenGenerated', 'loggedIn'];
	let currentState = state[0];

	async function createToken(event) {
		try {
			event.preventDefault();

			let formData = new FormData(event.target);
			const email = formData.get('email');
			const password = formData.get('password');

			let authRequest = await fetch('/auth', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json'
				},
				body: JSON.stringify({ email, password })
			});

			let authRequestBody = await authRequest.json();

			user = authRequestBody.user;
			token = authRequestBody.token;
			currentState = state[1];
		} catch (error) {
			console.error(error);
		}
	}

	async function createSession() {
		session = await account.createSession({
			userId: user.$id,
			secret: token.secret
		});
		currentState = state[2];
	}

	async function logout() {
		token = '';
		session = '';
		currentState = state[0];
		await account.deleteSession({
			sessionId: 'current'
		});
	}

	onMount(async () => {
		await logout();
	});
</script>

<h1>Custom Token Auth Demo</h1>

{#if currentState == state[0]}
	<div>
		<h2>Login</h2>
		<form on:submit={createToken}>
			<div>
				<label for="email">Email</label>
				<input type="email" id="email" name="email" placeholder="Enter any email id" required />
			</div>
			<div>
				<label for="password">Password (Enter code: 123456)</label>
				<input type="text" id="password" name="password" placeholder="123456" required />
			</div>
			<button type="submit">Login</button>
		</form>
	</div>
{:else if currentState == state[1]}
	<div>
		<h2>Token secret: {token.secret}</h2>
		<button on:click={createSession}>Generate session</button>
	</div>
{:else if currentState == state[2]}
	<div>
		<h2>Session details</h2>
		<pre>{JSON.stringify(session, undefined, 4)}</pre>
		<button type="submit" on:click={logout}>Logout</button>
	</div>
{/if}
```

The page has a `currentState` property, which helps track whether a user has yet to start the auth flow (`state[0]`), has received the token secret (`state[1]`), and has an active session (`state[2]`). In `state[0]`, the user submits their email and password (hardcoded to `123456` for the demo), which are then sent to our server function. In `state[1]`, the user can generate a session using the token secret and user ID received from our server function. In `state[2]`, the user can see the current session object, thus showcasing a successful login.

## Testing the project

Once we have complete the steps above, the project is ready to test. Run the following command in the terminal:

```bash
npm run dev
```

# Next steps

And with that, our demo project to try custom token authentication in Appwrite is ready! You can find the application’s complete source code at this [GitHub Repo](https://github.com/appwrite-community/appwrite-custom-token-auth-demo).

Additionally, if you would like to learn more about Appwrite Auth, here are some resources:

- [Appwrite Auth docs](https://appwrite.io/docs/products/auth): Learn more about how to use Appwrite Auth.
- [Appwrite Discord](https://discord.com/invite/appwrite): Connect with other developers and the Appwrite team for discussion, questions, and collaboration.
