---
layout: post
title: Improving UX with passwordless authentication
description: Understanding what passwordless authentication is and how it is a game-changer for both user experience and security.
date: 2024-03-18
cover: /images/blog/improve-ux-passwordless-auth/cover.avif
timeToRead: 6
author: aditya-oberai
category: authentication
faqs:
  - question: "What is passwordless authentication?"
    answer: "Passwordless authentication verifies a user without asking them to type a password. Common methods include passkeys, magic links sent over email, and one time passcodes delivered by email or SMS."
  - question: "Is passwordless authentication actually more secure than passwords?"
    answer: "Yes, in most cases. Passwords get reused, phished, and leaked in breaches, while passkeys and one time codes do not have a long lived secret a user can spill. The remaining attack surface shifts to the delivery channel (email, SMS) or the device itself."
  - question: "Which passwordless methods does Appwrite support?"
    answer: "Appwrite supports magic URLs, email OTPs, and phone (SMS) authentication out of the box. They are all available through [Appwrite Auth](/docs/products/auth) and can be combined with OAuth providers in the same project."
  - question: "Should I still offer email and password if I use passwordless?"
    answer: "Not necessarily. Many products go fully passwordless to reduce support load from password resets. If your users are not all on modern devices, keeping a fallback (like email OTPs) avoids leaving anyone locked out."
  - question: "How do magic links work behind the scenes?"
    answer: "The server generates a one time token, embeds it in a URL, and emails it to the user. When the user clicks the link, the token is verified and a session is created. Tokens are single use and expire quickly to limit replay attacks."
  - question: "Are passkeys the same as magic links?"
    answer: "No. Passkeys are cryptographic credentials stored on a device and verified with biometrics or a PIN. Magic links rely on email delivery and a one time URL. Passkeys are stronger against phishing but require browser and device support."
---

Today, as concerns about security and user convenience only grow with digital activity, traditional password-based authentication systems are becoming a relic of the past. In recent times, we have seen a rise in passwordless systems, which are increasingly seeing adoption in both small and big companies alike, such as Expensify (whose transition was also covered in a [Forbes article](https://www.forbes.com/sites/quickerbettertech/2023/05/29/on-technology-expensify-forces-passwordless-on-its-users-and-good-for-them/?sh=397a7b017cac) in 2023. A [survey from Enterprise Strategy Group](https://research.esg-global.com/reportaction/SecuringTheIdentityPerimeterReport/Toc), a division of TechTarget, in 2022 also revealed that:

- 31% of respondents picked passwordless authentication as their top identity-related activity
- 34% of respondents chose passwordless authentication among their top three identity-related activities
- 54% of respondents have started to transition to passwordless authentication
- Of organizations transitioning to passwordless strategies, more than 50% experienced a significant positive impact on risk reduction and improved UX
- Almost two-thirds reported increased efficiency for IT and security teams

Therefore, in this blog, we'll explore what passwordless authentication is, the drawbacks of conventional methods, and how embracing passwordless solutions can be a game-changer.

# The drawbacks of traditional password-based systems

[As per an article by Lastpass](https://blog.lastpass.com/2023/07/pervasive-password-less-protection-the-solution-to-the-compromised-credentials-crisis), passwords need to be treated like a user experience today because repeated login attempts, forgotten passwords, mandatory password changes, and looking up passwords in a document or notebook only slow employees down and create friction in their workflow. Traditional password systems, despite their ubiquity, come with significant drawbacks, including:

- **Password fatigue**: Users struggle with remembering numerous passwords for different accounts.
- **Security risks**: Weak or reused passwords increase vulnerability to hacking and data breaches.
- **User inconvenience**: Managing complex passwords is often cumbersome and frustrating and only causes more fatigue.
- **Time-consuming**: Resetting forgotten passwords and managing accounts adds unnecessary steps.
- **Phishing vulnerabilities**: Traditional passwords are susceptible to phishing attacks and can open up new attack vectors for a product.
- **Limited physical security**: Passwords can be easily observed or stolen, especially in public or shared spaces.

# What is passwordless authentication?

Passwordless authentication is exactly what it sounds like – a way to authenticate users without the need for passwords. This paradigm shift in user verification employs methods like *passkeys* (fingerprint, facial scans, or screen lock), *magic links* (one-time clickable links sent via email or SMS), and *one-time passcodes* (OTPs) sent to a user's device. These methods are not only innovative but also align with the natural human tendency to seek convenience and simplicity.

## Benefits of passwordless authentication for user experience

The transition to passwordless authentication brings a myriad of benefits:

- **Enhanced security**: By eliminating passwords, we inherently reduce the risk of password-related breaches.
- **Improved ease of use**: Users no longer need to remember or manage a plethora of passwords. This simplifies the login process, leading to a more frictionless user experience.
- **Accelerated process**: Authentication becomes quicker, with just a click or a touch, streamlining the user's journey.
- **Higher authentication speed**: Users can access services faster, without the delay of typing a password.
- **Enhanced user retention**: Easier login processes can lead to lower user drop-off rates.
- **Accessibility for all users**: Such methods can be more accessible for users who have difficulty remembering passwords or have physical disabilities that make typing challenging.
- **Lower support costs**: A lack of passwords reduces the volume of password reset requests and related support issues, saving an immense amount of IT and support effort and cost.
- **Scalability**: Easily adapts to growing user bases without the need for extensive password management systems.
- **Reduced risk of internal threats**: Minimizes the risk of password theft or misuse by internal actors within an organization.

## Tradeoffs of passwordless authentication

While the benefits are substantial, passwordless authentication isn't without its tradeoffs, such as:

- **Implementation complexity**: It can be more challenging and resource-intensive to implement than traditional methods.
- **Hardware dependencies**: Some methods require specific hardware, like biometric scanners, which add further costs and may not be as readily accessible everywhere.
- **Privacy and security concerns**: The collection of biometric data raises privacy issues for individuals as well as opens up newer security challenges in regard to how the data must be stored.
- **User adaptation**: Users are often accustomed to well-set systems and may need time to adapt to new authentication methods.
- **Potential technical issues**: Some methods rely on the proper functioning of user devices and external systems (like email or SMS services), which the company may not have control of.

# Implementing passwordless authentication using Appwrite

Appwrite Authentication also features three commonly used passwordless authentication methods: [**magic links**](/docs/products/auth/magic-url), [**email OTPs**](/docs/products/auth/email-otp), and [**phone authentication**](/docs/products/auth/phone-sms), which can be integrated into applications seamlessly with Appwrite’s client-side SDKs. This approach not only enhances security but also significantly improves the user experience by simplifying and streamlining the login process.

{% tabs %}
{% tabsitem #magicurl title="Magic URLs" %}

Magic URL authentication is a two-step process in Appwrite.

First, we initialize the login process by sending an email with the magic URL. If the email has never been used, a new account is also generated.

```client-web
import { Client, Account, ID } from "appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

const account = new Account(client);

const user = await account.createMagicURLToken({
    userId: ID.unique(),
    email: 'email@example.com',
    url: '<APP_URL>'
});
```

After receiving the secret from an email, you can create a session for the user.

```js
const urlParams = new URLSearchParams(window.location.search);
const secret = urlParams.get('secret');
const userId = urlParams.get('userId');

const user = await account.createSession({
    userId,
    secret
});
```

{% /tabsitem %}

{% tabsitem #emailotp title="Email OTPs" %}

Email OTP authentication is a two-step process in Appwrite.

First, we initialize the login process by sending an email. If the email has never been used, a new account is also generated.

```client-web
import { Client, Account, ID } from "appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

const account = new Account(client);

const sessionToken = await account.createEmailToken({
    userId: ID.unique(),
    email: 'email@example.com'
});

const userId = sessionToken.userId;

```

After receiving the secret (6-digit number) in the email, you can use it along with the returned user ID to confirm the user.

```js
const session = await account.createSession({
    userId,
    secret: '<SECRET>'
});
```

{% /tabsitem %}

{% tabsitem #phoneotp title="Phone auth" %}

Phone authentication is a two-step process in Appwrite.

First, we initialize the login process by sending an SMS. If the phone number has never been used, a new account is also generated.

```client-flutter
import 'package:appwrite/appwrite.dart';

final client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

final account = Account(client);

final token = await account.createPhoneToken(
    userId: ID.unique(),
    phone: '+14255550123'
);

final userId = token.userId;
```

After receiving the secret (6-digit number) in the SMS, you can use it along with the returned user ID to confirm the user.

```dart
final session = await account.createSession(
    userId: userId,
    secret: '<SECRET>'
);
```

{% /tabsitem %}
{% /tabs %}

Passwordless authentication stands at the forefront of a new era in digital security and user experience. My recommendation to all is to embrace the future – say goodbye to passwords and hello to a more secure, convenient digital experience.

Learn more about Appwrite Authentication from our [docs](/docs/products/auth) and join our [Discord community](https://appwrite.io/discord) to interact with fellow developers using the same.