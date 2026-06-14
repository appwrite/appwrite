---
layout: post
title: "Announcing 2FA: Enhance your application's security."
description: Improve your application's security by adding an additional layer of protection.
date: 2024-02-27
cover: /images/blog/announcing-2fa.avif
timeToRead: 6
author: eldad-fux
category: product, announcement
featured: false
faqs:
  - question: "How do I enable two-factor authentication in Appwrite?"
    answer: "Call `account.updateMFA({ enabled: true })` from the client SDK on an authenticated user. The user then needs at least two registered factors (for example email plus TOTP) before MFA is enforced on login. See [Appwrite Auth](/docs/products/auth) for the full flow."
  - question: "Which 2FA methods does Appwrite support?"
    answer: "Appwrite Auth supports three second-factor methods: TOTP via authenticator apps like Google Authenticator or Authy, one-time codes sent via email, and one-time codes sent via SMS. You can combine them or let users register multiple factors on the same account."
  - question: "What is TOTP and how is it different from SMS OTP?"
    answer: "TOTP (Time-based One-Time Password) generates a short-lived 6-digit code from a shared secret on the user's device, with no network round trip. SMS OTP delivers a code over the cell network, which adds latency and cost and is vulnerable to SIM swapping. TOTP is generally preferred for security and reliability."
  - question: "What is the user_more_factors_required error in Appwrite?"
    answer: "`user_more_factors_required` is the error Appwrite returns when a user has logged in with their primary factor but still needs to complete a second factor. Your app should catch this error and redirect the user to the second-factor challenge page instead of treating it as a failure."
  - question: "Can I use 2FA with OAuth and email/password in the same app?"
    answer: "Yes. Appwrite 2FA layers on top of any primary authentication method, so users who sign in with email and password, magic URL, OAuth, or phone OTP can all be required to complete a second factor. The MFA flow is the same regardless of the initial login method."
  - question: "Why should I add 2FA to my application?"
    answer: "Two-factor authentication significantly reduces the risk of account takeover from credential stuffing or leaked passwords because an attacker also needs the user's second factor. It also helps meet compliance requirements in regulated industries and signals to users that you take their security seriously."
---

At Appwrite, our mission is to eliminate technical barriers. A key part of this mission is making your applications more secure. With Appwrite 1.5, we’re excited to announce that we are adding support for Two-Factor Authentication (2FA) to Appwrite Authentication, providing an extra layer of security for your end users' accounts.

# Introducing Appwrite 2FA

Appwrite 2FA helps increase the security of your apps by adding an additional layer of protection after the regular authentication process. When 2FA is enabled, a malicious actor must compromise multiple authentication factors to gain unauthorized access. This offers a number of benefits:

- **Increased account security**: Adds a critical second verification step, significantly reducing the risk of unauthorized access.
- **Mitigation of data breach risks**: In today's digital age, where data breaches are increasingly common, 2FA acts as a formidable barrier against unauthorized access, protecting sensitive user information.
- **Compliance with security regulations**: 2FA helps adhere to strict security standards and regulations, safeguarding not just user data but also ensuring that your application meets industry-specific legal requirements.
- **Trust and integrity**: By implementing 2FA, you signal to your users a strong commitment to protecting their data, thereby building trust and maintaining the integrity of your application.

## Methods available for 2FA

Currently, Appwrite offers three methods for implementing the second factor:

| Method | Description |
| --- | --- |
| Time-based one-time passwords (TOTP) | This method allows a user to leverage common authenticator apps like Google Authenticator or Twilio Authy that provide a TOTP for authentication. |
| Emails | This method sends a one-time password (6-digit code) to the user’s email address for authentication. |
| SMS | This method sends a one-time password (6-digit code) to the user’s phone number for authentication. |

# Implementing 2FA in your apps

One great aspect of Appwrite 2FA is that it can be used in conjunction with any existing authentication method you have implemented using Appwrite Auth. You can implement 2FA in your application via the following several steps.

## Step 1: Enable 2FA on an account

To use 2FA, it needs to be enabled on a user’s account. This can be achieved by calling `account.updateMFA()`.

```client-web
import { Client, Account } from "appwrite";

// Init SDK
const client = new Client();

client
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<PROJECT_ID>'); // Your project ID

const account = new Account(client);

// Include any account creation/management steps

const mfa = await account.updateMFA({
    enabled: true
}); // Enables 2FA
```

> Note: You will need to have added more than 1 factor of authentication to an account before the MFA is enforced.

## Step 2: Initialize login and check for multiple factors

Begin your login flow with the default authentication method used by your app (for example, email password).

```js
const user = await account.createEmailPasswordSession({
    email: 'email@example.com',
    password: 'password'
});
```

Upon successful login in the first authentication step, check the status of the login by calling `account.get()`. If you receive the error `user_more_factors_required`, redirect to perform the second factor.

```js
try {
    const response = await account.get();
} catch (error) {
    if (error.type === `user_more_factors_required`){
        // redirect to perform 2FA
    }
    else {
        // handle other errors
    }
}
```

## Step 3: Check enabled factors

To confirm which providers are enabled for an account, you can call `account.listProviders()`.

```js
const factors = await account.listMFAFactors();

console.log(factors);
```

The returned object will be formatted as follows:

```js
{
    totp: true, // time-based one-time password
    email: false, // email
    phone: true // phone
}
```

## Step 4: Create challenge

Based on the enabled providers, you can initialize the additional auth step. Calling these methods will send a challenge to the user. Ensure you save the challenge ID to complete the challenge in a later step.

```js
const challenge = await account.createMFAChallenge({
    factor: 'phone'
});

// Save the challenge ID to complete the challenge later
const challengeId = challenge.$id;
```

## Step 5: Complete challenge

Once the user receives the challenge code, you can pass the code back to Appwrite to complete the challenge.

```js
const challenge = await account.updateMFAChallenge({
    challengeId: '<CHALLENGE_ID>',
    otp: '<OTP>'
});
```

And just like that, you have implemented 2FA using Appwrite!

While Appwrite currently allows two factors of authentication, we intend to make more factors available soon.

# Resources

Visit our documentation to learn more about Appwrite, join us on Discord to be part of the discussion, view our blog and YouTube channel, or visit our GitHub repository to see our open-source code.

- [Docs](/docs/products/auth/mfa)
- [Discord](https://appwrite.io/discord)
- [Blog](/blog)
- [YouTube](https://www.youtube.com/channel/UCtBJ1v69gm8NgbCju_03Fiw)
- [GitHub](https://github.com/appwrite/appwrite)

2FA will be available as part of the Appwrite 1.5 release on [GitHub](https://github.com/appwrite/appwrite) and [Cloud](https://cloud.appwrite.io/register) in March 2024.
