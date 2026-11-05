---
layout: integration
title: Phone auth with Twilio
description: Authenticate users via OTPs sent through SMS using Twilio
date: 2024-07-30
featured: false
isPartner: true
isNew: false
cover: /images/integrations/phone-auth-twilio/cover.avif
category: auth
product: 
    avatar: '/images/integrations/avatars/twilio.avif'
    vendor: Twilio
    description: 'Twilio is a cloud communications platform that provides programmable communication tools using web APIs and SDKs.'
platform: 
    - 'Self-hosted'
images: 
    - /images/integrations/phone-auth-twilio/cover.avif
    - /images/integrations/phone-auth-twilio/twilio-console.avif
    - /images/integrations/phone-auth-twilio/user.avif
---

Twilio is a cloud communications platform that provides programmable communication tools for making and receiving phone calls, sending and receiving text messages, and performing other communication functions using web APIs and SDKs.

# How does the integration work?

If you are using self-hosting Appwrite, you can use the Twilio provider to deliver OTPs (one-time passwords) for phone authentication in Appwrite Auth. This is a great passwordless alternative to standard authentication methods, especially for mobile apps, and has several benefits, such as enhanced security, higher user convenience, reduced password fatigue, and easier fraud prevention.

Phone authentication via OTPs is also available on [Appwrite Cloud](https://cloud.appwrite.io) without the need to manually integrate a provider.

# How to implement

To implement the Twilio provider for phone authentication, there are several steps you must complete:

## Step 1: Sign up for Twilio

First, you must [sign up for a Twilio account](https://www.twilio.com/try-twilio), upgrade your account (in case you want to message numbers other than your own), and purchase a phone number with the SMS capability.

![Twilio console](/images/integrations/phone-auth-twilio/twilio-console.avif)

Ensure you save the following information for later use:

| Field name |  |
| --- | --- |
| Account SID | Head to **Twilio console** > **Account info** > **Account SID**. |
| Auth token | Head to **Twilio console** > **Account info** > **Auth Token**. |
| Phone number | You can access numbers by navigating to your **Twilio console** > **Develop** > **Phone Numbers** > **Manage** > **Active Numbers**. |

## Step 2: Add Twilio provider to your Appwrite instance

For this step, you must [self-host Appwrite](https://appwrite.io/docs/advanced/self-hosting) if you haven’t already.

Visit the `.env` file created for your Appwrite instance and update the following environment variables:

```bash
_APP_SMS_PROVIDER=sms://[ACCOUNT_SID]:[AUTH_TOKEN]@twilio	
_APP_SMS_FROM=[TWILIO_PHONE_NUMBER]
```

After that, run the following Docker Compose commands in your terminal to restart your Appwrite containers and verify if the changes have been successfully applied:

```bash
docker compose up -d --force-recreate
docker compose exec appwrite vars
```

## Step 3: Test the provider

To test the provider, you must [implement the phone (SMS) authentication flow](https://appwrite.io/docs/products/auth/phone-sms) in a web or mobile app. On successful login, you should see a user on the **Auth** page of your Appwrite project with their phone number visible as the identifier.

![User](/images/integrations/phone-auth-twilio/user.avif)

# Read more about Twilio and Appwrite Auth

If you would like to learn more about phone authentication with Twilio and Appwrite Auth, we have some resources that you should visit:

- [Sign up for Twilio](https://www.twilio.com/try-twilio)
- [SMS delivery docs for Appwrite (self-hosted)](https://appwrite.io/docs/advanced/self-hosting/sms)
- [Appwrite phone auth docs](https://appwrite.io/docs/products/auth/phone-sms)
- [Appwrite Accounts API reference](https://appwrite.io/docs/references/cloud/client-web/account)
