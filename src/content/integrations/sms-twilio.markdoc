---
layout: integration
title: SMS with Twilio
description: Send customized SMS messages to your users
date: 2024-07-30
featured: false
isPartner: true
isNew: false
cover: /images/integrations/sms-twilio/cover.avif
category: messaging
product: 
    avatar: '/images/integrations/avatars/twilio.avif'
    vendor: Twilio
    description: 'Twilio is a cloud communications platform that provides programmable communication tools using web APIs and SDKs.'
platform: 
    - 'Self-hosted'
images: 
    - /images/integrations/sms-twilio/cover.avif
    - /images/integrations/sms-twilio/twilio-console.avif
    - /images/integrations/sms-twilio/config.avif
    - /images/integrations/sms-twilio/sms.avif
---

Twilio is a cloud communications platform that provides programmable communication tools for making and receiving phone calls, sending and receiving text messages, and performing other communication functions using web APIs and SDKs.

# How does the integration work?

You can use the Twilio provider in Appwrite Messaging to send customized SMS messages to your users. These SMS messages can be sent immediately or scheduled. You can send SMS messages for purposes like reminders, promotions, announcements, and even custom authentication flows.

# How to implement

To implement the Twilio provider in Appwrite Messaging, there are several steps you must complete:

## Step 1: Sign up on Twilio

First, you must [sign up for a Twilio account](https://www.twilio.com/try-twilio), upgrade your account (in case you want to message numbers other than your own), and purchase a phone number with the SMS capability.

![Twilio console](/images/integrations/sms-twilio/twilio-console.avif)

Ensure you save the following information for later use:

| Field name |  |
| --- | --- |
| Account SID | Head to **Twilio console** > **Account info** > **Account SID**. |
| Auth token | Head to **Twilio console** > **Account info** > **Auth Token**. |
| Phone number | You can access numbers by navigating to your **Twilio console** > **Develop** > **Phone Numbers** > **Manage** > **Active Numbers**. |

## Step 2: Add Twilio provider to your Appwrite project

For this step, you must [create an account on Appwrite Cloud](https://cloud.appwrite.io/register) or [self-host Appwrite](https://appwrite.io/docs/advanced/self-hosting) if you haven’t already. In your Appwrite project, head over to the **Messaging** page, click on the **Providers** tab, and **create a new SMS provider**. 

Select **Twilio** and add the **account SID**, **auth token**, and **phone number** that you saved from the Twilio console.

![Twilio provider config](/images/integrations/sms-twilio/config.avif)

## Step 3: Test the Twilio provider

Before you proceed, you must already have at least one [topic](https://appwrite.io/docs/products/messaging/topics) or [target](https://appwrite.io/docs/products/messaging/targets) set up. 

Once the provider is set up, you can go to the **Messages** tab on the **Messaging** page and **create an SMS message**. You can add the test message, configure the users to send the message to, and pick when the message should be sent out. 

![SMS message console](/images/integrations/sms-twilio/sms.avif)

# Read more about Twilio and Appwrite Messaging

If you would like to learn more about Twilio and Appwrite Messaging, we have some resources that you should visit:

- [Sign up for Twilio](https://www.twilio.com/try-twilio)
- [Set up the Twilio provider in Appwrite Messaging](https://appwrite.io/docs/products/messaging/twilio)
- [Send SMS messages through Appwrite Messaging](https://appwrite.io/docs/products/messaging/send-sms-messages)
- [How tools like Twilio can simplify messaging for developers](https://appwrite.io/blog/post/simplify-messaging-twilio)
- [Appwrite Messaging API reference](https://appwrite.io/docs/references/cloud/server-nodejs/messaging)