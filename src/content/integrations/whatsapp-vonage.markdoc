---
layout: integration
title: WhatsApp with Vonage
description: Send automated replies to messages on WhatsApp
date: 2024-07-30
featured: false
isPartner: true
isNew: false
cover: /images/integrations/whatsapp-vonage/cover.avif
category: messaging
product: 
    avatar: '/images/integrations/avatars/vonage.avif'
    vendor: Vonage
    description: 'Vonage is a cloud communications provider that offers residential telecommunications services based on voice over Internet Protocol as well as programmable communications tools via web APIs.'
platform: 
    - 'Self-hosted'
    - 'Cloud'
images: 
    - /images/integrations/whatsapp-vonage/cover.avif
    - /images/integrations/whatsapp-vonage/settings.avif
    - /images/integrations/whatsapp-vonage/template.avif
    - /images/integrations/whatsapp-vonage/variables.avif
    - /images/integrations/whatsapp-vonage/demo.avif
---

Vonage is a cloud communications provider that offers residential telecommunications services based on voice over Internet Protocol as well as programmable communications tools such as phone calls, SMSes, WhatsApp messages, etc. via web APIs.

# How does the integration work?

You can use a pre-built Appwrite Function template that is integrated with Vonage to build a WhatsApp-based chatbot that can accept your messages and respond based on pre-defined logic.

# How to implement

To implement the **WhatsApp with Vonage** Appwrite Function template, there are several steps you must complete:

## Step 1: Sign up on Vonage

First, [sign up for a Vonage account](https://ui.idp.vonage.com/ui/auth/registration) to use the Vonage Communications APIs. This will create a trial account and give you access to the Vonage API dashboard.

![Vonage API settings](/images/integrations/whatsapp-vonage/settings.avif)

Ensure you save the following information for later use:

| Field name |  |
| --- | --- |
| API key | Head to the **Vonage API dashboard** > **API Settings** > **API key** |
| API secret | Head to the **Vonage API dashboard** > **API Settings** > **API secret** |
| Signature secret | Head to the **Vonage API dashboard** > **API Settings** > **Signature secret** |
| WhatsApp phone number | Head to the **Vonage API dashboard** > **Developer Tools** > **Messages API Sandbox** > enable **WhatsApp** and copy phone number |

Once the Appwrite Function is deployed, we will return here to add the Function URL as the **Inbound Webhook** link.

## Step 2: Create the Appwrite Function

For this step, [create an account on Appwrite Cloud](https://cloud.appwrite.io/register) or [self-host Appwrite](https://appwrite.io/docs/advanced/self-hosting) if you haven’t already. In case you decide to self-host Appwrite, there are [additional setup steps](https://appwrite.io/docs/advanced/self-hosting/functions) to use Appwrite Function templates.

In your Appwrite project, go to the **Functions** page, click on the **Templates** tab, search for ‘Vonage’, and select the **WhatsApp with Vonage** function template. 

![Function template](/images/integrations/whatsapp-vonage/template.avif)

During the setup process, add the Vonage **API key**, **API secret, signature secret**, and **WhatsApp phone number** in the **Variables** step**.** 

![Env variables](/images/integrations/whatsapp-vonage/variables.avif)

Then, create a new repository with the default branch and root directory settings. You can edit this repository later to update the function logic. Once the function is ready, visit the **Domains** tab on the function page and copy the domain to add to the **Inbound Webhook** link on the **Messages API Sandbox** page on your Vonage account.

## Step 3: Test the integration

Open **WhatsApp** on your phone, join the Vonage WhatsApp Channel (via the steps mentioned on the *Messaging API Sandbox* on *Vonage*), and send any message to the WhatsApp number. You shall receive a message in the format: `Hi there! You sent me: <SENT MESSAGE>`

![demo](/images/integrations/whatsapp-vonage/demo.avif)

# Read more about Vonage and Appwrite

If you would like to learn more about Vonage, we have some resources that you should visit:

- [Sign up for Vonage](https://ui.idp.vonage.com/ui/auth/registration)
- [Send WhatsApp messages with Vonage and Appwrite Functions](https://appwrite.io/blog/post/function-template-whatsapp-vonage)
- [Appwrite Functions products docs](https://appwrite.io/docs/products/functions)