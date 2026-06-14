---
layout: post
title: Improving user security in your web apps with email OTP auth
description: Understand how email OTP authentication works and how you can implement it in a SvelteKit application.
date: 2024-04-05
cover: /images/blog/email-otp-auth-sveltekit/cover.avif
timeToRead: 6
author: aditya-oberai
category: authentication
faqs:
  - question: "What is email OTP authentication?"
    answer: "Email OTP authentication lets users sign in with a 6-digit one-time code sent to their email instead of a password. It is a passwordless flow that pairs well with magic URLs and other modern auth methods, and it is part of [Appwrite Auth](/docs/products/auth)."
  - question: "How is email OTP different from magic URL login?"
    answer: "Magic URL sends a clickable link to the inbox, while email OTP sends a numeric code the user enters in the app. OTPs are better when users may not be signed into their email on the same device, when you want to avoid redirect flows, or when deep linking on mobile is awkward. The trade-off is more typing and a shorter expiry window."
  - question: "How do I implement email OTP in a SvelteKit app with Appwrite?"
    answer: "Use the Appwrite Web SDK in your SvelteKit app, call `createEmailToken` with a user ID and email to send the code, then call `createSession` with the user ID and the entered code to log the user in. The full flow uses two screens: one for the email and one for the OTP."
  - question: "Do I need Appwrite Cloud or can I self-host for email OTP?"
    answer: "Both work. On [Appwrite Cloud](https://cloud.appwrite.io) the feature is ready out of the box. On a self-hosted instance, configure SMTP credentials in your `.env` file so Appwrite can actually deliver the code, then restart with `docker compose up -d`."
  - question: "Is email OTP secure enough for production apps?"
    answer: "Email OTP is more secure than passwords for most users because it removes password reuse and weak passwords from the equation. The trade-off is dependency on email delivery and inbox security. For higher-assurance use cases, pair email OTP with multi-factor authentication using TOTP."
  - question: "What other passwordless options does Appwrite support?"
    answer: "Appwrite Auth supports email OTP, phone OTP via SMS, magic URLs, OAuth providers, passkeys, and anonymous sessions. You can combine them with MFA factors like TOTP or email codes for a layered auth strategy. See the [Appwrite Auth docs](/docs/products/auth) for the full list."
---

To discover a balance between security and user convenience, one growing trend we have seen recently is the implementation of passwordless authentication. Today, both small and large companies are transitioning to using passwordless authentication methods over traditional password-based ones, such as Expensify (whose transition was also covered in a Forbes [article](https://www.forbes.com/sites/quickerbettertech/2023/05/29/on-technology-expensify-forces-passwordless-on-its-users-and-good-for-them/?sh=397a7b017cac) in 2023). Appwrite has always maintained support for both types of authentication, featuring phone-based OTPs (one-time passwords) and magic URLs in our list of authentication methods.

With the recent Appwrite 1.5 release, we added a new passwordless authentication method: email OTPs. In this blog, you’ll learn how email OTP authentication works and how you can implement it in a SvelteKit application.

# What is email OTP authentication?

[Email OTP authentication](https://appwrite.io/docs/products/auth/email-otp) lets users create accounts using their email address and sign in using a 6-digit code delivered to their email inbox. This method is similar to [Magic URL login](https://appwrite.io/docs/products/auth/magic-url) but can provide a better user experience in some scenarios.

## Email OTP vs magic URL

Email OTP authentication sends an email with a 6-digit code that a user needs to enter into the app, while magic URL authentication delivers a clickable button or link to the user's inbox. Both allow passwordless login flows with different advantages.

| Benefits of email OTP | Downsides of email OTP |
| --- | --- |
| Doesn't require the user to be signed into their email inbox on the device | Expires quicker |
| Doesn't disturb the application flow with a redirect | Requires more inputs from the user |
| Doesn't require deep linking on mobile apps |  |

# Implementing email OTP in a SvelteKit app

Now that we have a basic understanding of email OTP authentication, let’s learn how we can implement it in a SvelteKit application.

## Set up Appwrite 1.5

You can use email OTP authentication either by [registering on Appwrite Cloud](https://cloud.appwrite.io/) or [self-hosting Appwrite](https://appwrite.io/docs/advanced/self-hosting) on your system or an external VM/VPS.

You can self-host Appwrite using the following Docker command:

```bash
docker run -it --rm \
    --volume /var/run/docker.sock:/var/run/docker.sock \
    --volume "$(pwd)"/appwrite:/usr/src/code/appwrite:rw \
    --entrypoint="install" \
    appwrite/appwrite:1.8.1
```

Once that is done, [set up email delivery](https://appwrite.io/docs/advanced/self-hosting/email) on your self-hosted Appwrite instance. This can be done by visiting your `appwrite` directory and updating the `.env` file in a similar manner as follows:

```
_APP_SMTP_HOST=smtp.sendgrid.net
_APP_SMTP_PORT=587
_APP_SMTP_SECURE=tls
_APP_SMTP_USERNAME=YOUR-SMTP-USERNAME
_APP_SMTP_PASSWORD=YOUR-SMTP-PASSWORD
_APP_SYSTEM_EMAIL_ADDRESS=YOUR-SENDER-EMAIL
```

Don’t forget to run `docker compose up -d` after you update the environment variables to ensure the changes are implemented in your instance.

Once your Appwrite instance is set up, create a project and copy both the Appwrite API endpoint and project ID.

## Create your SvelteKit web app

In order to create your SvelteKit application, open your terminal and enter the command `npm create svelte@latest` to create a project.

```bash
npm create svelte@latest

create-svelte version 6.0.10

┌  Welcome to SvelteKit!
│
◇  Where should we create your project?
│  appwrite-email-otp
│
◇  Which Svelte app template?
│  Skeleton project
│
◇  Add type checking with TypeScript?
│  No
│
◇  Select additional options (use arrow keys/space bar)
│  Add ESLint for code linting, Add Prettier for code formatting
│
└  Your project is ready!
```

Once that is done, enter the directory and install any existing dependencies.

```bash
cd appwrite-email-otp
npm i
```

Also, create a file `.env` in the root directory and add your Appwrite API endpoint and project ID.

```
PUBLIC_APPWRITE_ENDPOINT=
PUBLIC_APPWRITE_PROJECT_ID=
```

## Install the Appwrite Web SDK and prepare the app logic

Once your project is ready, install the Appwrite Web SDK using NPM:

```bash
npm i appwrite
```

As soon as that’s done, we can start preparing our application logic. For that, visit the `src/lib` directory and create a file `appwrite.js`. Add the following code to the same.

```js
import { PUBLIC_APPWRITE_ENDPOINT, PUBLIC_APPWRITE_PROJECT_ID } from "$env/static/public";
import { Client, Account } from "appwrite";

const client = new Client()
    .setEndpoint(PUBLIC_APPWRITE_ENDPOINT)
    .setProject(PUBLIC_APPWRITE_PROJECT_ID);

export const account = new Account(client);
```

In the same directory, create a file `user.js` and add the following code.

```js
import { account } from "./appwrite";
import { ID } from "appwrite";

export const user = {
    createOtp: async (email) => {
        return await account.createEmailToken({
            userId: ID.unique(),
            email,
            phrase: true
        });
    },

    verifyOtp: async (userId, secret) => {
        return await account.createSession({
            userId,
            secret
        });
    }
}
```

## Update the application UI

Once the authentication logic is ready, we can create the web app’s user interface. To prioritize ease of understanding and simplicity, the example will not feature any styling-related code.

In the `src/routes` directory, visit the file `+page.svelte` and replace it with the following code.

```html
<script>
    import { user } from "$lib/user.js";

    var token;
    var securityPhrase = "Create OTP first to get the security phrase.";

    async function createOtp(e) {
        e.preventDefault();
        const formData = new FormData(e.target);
        token = await user.createOtp(formData.get('email'));
        securityPhrase = `Security phrase: ${token.phrase}`;
        alert("OTP sent to email");
    }

    async function verifyOtp(e) {
        e.preventDefault();
        const formData = new FormData(e.target);
        const response = await user.verifyOtp(token.userId, formData.get('otp'));
        alert("OTP verified");
    }
</script>

<section>
    <div class="container">
        <div id="email">
            <h2>Enter Email</h2>
            <form on:submit={createOtp}>
                <input type="email" name="email" id="email" placeholder="team@appwrite.io" required>
                <button class="button" type="submit">Submit</button>
            </form>
        </div>

        <div id="otp">
            <h2>Enter OTP</h2>
            <form on:submit={verifyOtp}>
                <input type="text" name="otp" id="otp" placeholder="012345" required>
                <button class="button" type="submit">Submit</button>
            </form>
            <p>{securityPhrase}</p>
        </div>
    </div>
</section>
```

This UI features both steps of the email OTP flow, entering the email to send an OTP followed by entering the OTP to login. It also features the security phrase sent on the email along with the OTP so that the user can verify that a third party didn’t initiate the authentication flow.

Once this is done, you can test the application by running the command `npm run dev` and opening the app URL in your browser.

# Next steps

And with that, our demo application is ready. If you liked this project or want to investigate the full codebase, visit the [GitHub repository](https://github.com/adityaoberai/appwrite-email-otp-demo).

For more information about Appwrite Authentication, visit the following resources:

- [Appwrite Authentication Docs](https://appwrite.io/docs/products/auth): These docs provide more information on how to use the different methods offered under Appwrite Authentication.
- [Appwrite Discord](https://discord.com/invite/appwrite): Connect with other developers and the Appwrite team for discussion, questions, and collaboration.
