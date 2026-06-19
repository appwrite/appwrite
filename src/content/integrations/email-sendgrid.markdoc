---
layout: integration
title: Email with SendGrid
description: Send customized emails to your users
date: 2024-07-30
featured: false
isPartner: true
isNew: false
cover: /images/integrations/email-sendgrid/cover.avif
category: messaging
product: 
    avatar: '/images/integrations/avatars/sendgrid.avif'
    vendor: SendGrid
    description: 'SendGrid is a cloud-based service that provides email delivery and marketing solutions.'
platform: 
    - 'Self-hosted'
    - 'Cloud'
images: 
    - /images/integrations/email-sendgrid/cover.avif
    - /images/integrations/email-sendgrid/welcome-guide.avif
    - /images/integrations/email-sendgrid/provider.avif
    - /images/integrations/email-sendgrid/email.avif
---

SendGrid is a cloud-based service that provides email delivery and marketing solutions. It helps businesses manage email communications, including transactional emails (like password resets and order confirmations) and marketing emails (newsletters and promotions). 

# How does the integration work?

You can use the SendGrid provider in Appwrite Messaging to send customized emails to your users for various purposes such as reminders, promotions, announcements, and even custom authentication flows. They can be sent immediately or scheduled for later. 

# How to implement

To implement the SendGrid provider in Appwrite Messaging, there are several steps you must complete:

## Step 1: Sign up on Sendgrid

First, you must [sign up for a SendGrid account](https://signup.sendgrid.com/) and finish your account setup.

![SendGrid welcome guide](/images/integrations/email-sendgrid/welcome-guide.avif)

Next, [create an API key](https://www.twilio.com/docs/sendgrid/ui/account-and-settings/api-keys) with full access and save the key for further usage. After, follow SendGrid's [Domain Authentication](https://www.twilio.com/docs/sendgrid/ui/account-and-settings/how-to-set-up-domain-authentication) process to authenticate your domain name. If you don’t own a domain, you can follow their [Single Sender Verification](https://www.twilio.com/docs/sendgrid/ui/sending-email/sender-verification) process to verify your Sender identity.

## Step 2: Add SendGrid provider to your Appwrite project

For this step, you must [create an account on Appwrite Cloud](https://cloud.appwrite.io/register) or [self-host Appwrite](https://appwrite.io/docs/advanced/self-hosting) if you haven’t already. In your Appwrite project, head over to the **Messaging** page, click on the **Providers** tab, and **create a new Email provider**.

![Setup email provider](/images/integrations/email-sendgrid/provider.avif)

Fill in the following fields:

| Field name |  |
| --- | --- |
| API key | The full access API key you saved from the SendGrid dashboard. |
| Sender email | The provider sends emails from this sender email. The sender email must either be an email under an authenticated domain or a verified sender identity. |
| Sender name | The sender name that appears in the emails sent from this provider. |
| Reply-to email | The reply-to email that appears in the emails sent from this provider. The reply-to email must either be an email under an authenticated domain or a verified sender identity. |
| Reply-to name | The reply-to name that appears in the emails sent from this provider. |

## Step 3: Test the SendGrid provider

Before you proceed, you must already have at least one [topic](https://appwrite.io/docs/products/messaging/topics) or [target](https://appwrite.io/docs/products/messaging/targets) set up.

Once the provider is set up, you can go to the **Messages** tab on the **Messaging** page and **create an email message**. You can add the test message, configure the users to send the message to, and pick when the message should be sent out. 

![Create email](/images/integrations/email-sendgrid/email.avif)

# Read more about Sendgrid and Appwrite Messaging

If you would like to learn more about Sendgrid and Appwrite Messaging, we have some resources that you should visit:

- [Sign up for SendGrid](https://signup.sendgrid.com/)
- [Set up the SendGrid provider in Appwrite Messaging](https://appwrite.io/docs/products/messaging/sendgrid)
- [Send email messages through Appwrite Messaging](https://appwrite.io/docs/products/messaging/send-email-messages)
- [Appwrite Messaging API reference](https://appwrite.io/docs/references/cloud/server-nodejs/messaging)