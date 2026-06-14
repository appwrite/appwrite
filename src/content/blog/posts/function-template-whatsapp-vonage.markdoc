---
layout: post
title: Send WhatsApp messages with Vonage and Appwrite Functions
description: Learn how to use an Appwrite Function Template to create a Whatsapp bot that will programmatically respond to incoming WhatsApp messages via Vonage.
date: 2023-11-15
cover: /images/blog/function-template-whatsapp-vonage/cover.avif
timeToRead: 5
author: aditya-oberai
category: integrations
faqs:
  - question: "What is an Appwrite Function template?"
    answer: "Function templates are prebuilt Appwrite Functions you can install in your project with a few clicks. They cover common integrations like WhatsApp via Vonage, Discord notifications, and Stripe webhooks, so you do not have to write the boilerplate yourself. See [Appwrite Functions](/docs/products/functions) for the product overview."
  - question: "Do I need a paid Vonage account to use this template?"
    answer: "Not for testing. The Vonage Messages API Sandbox lets you send and receive WhatsApp messages on a test number for free. You only need a paid Vonage plan once you go to production with your own approved WhatsApp Business number."
  - question: "How does the function receive incoming WhatsApp messages?"
    answer: "Vonage forwards every inbound WhatsApp message to a webhook URL you configure. After deploying the function, you copy the domain from the Appwrite Function's Domains tab and paste it as the Inbound Webhook in Vonage's Messages API Sandbox. The function is then called every time a user sends a WhatsApp message."
  - question: "How do I store WhatsApp environment variables securely?"
    answer: "Configure the Vonage API key, secret, signature secret, and WhatsApp number as Appwrite Function environment variables. Appwrite encrypts them at rest and only injects them into the function runtime, so they never leak to the client. Never hardcode them in your function source."
  - question: "Can I customize the auto reply behavior?"
    answer: "Yes. The template is open source, so you can fork the generated GitHub repository and change the reply logic to call OpenAI, look up data in [Appwrite Databases](/docs/products/databases), or route messages to your support team. Push your changes and Appwrite redeploys automatically."
  - question: "Can I use this approach for other messaging providers?"
    answer: "Yes. The pattern of webhook in, function processes, response out works the same with Twilio, MessageBird, or any other SMS/WhatsApp provider. You only need to swap the SDK and webhook format. Appwrite also has [Messaging](/docs/products/messaging) for sending push, SMS, and email, which can complement an inbound function like this."
---

Function templates are pre-built Appwrite Functions that can be integrated into your Appwrite project with just a few clicks. Using them, you can easily incorporate new features and integrations into your app without writing additional code or managing infrastructure.

One such integration you can implement using Appwrite Functions is **WhatsApp messaging** via **Vonage**. In this blog, you will learn how you can use an Appwrite Function Template to create a Whatsapp bot that will programmatically respond to incoming WhatsApp messages via Vonage.


## Setting up Vonage

The first step is to create a Vonage account. Head over to Vonage’s [website](https://www.vonage.com/) and [sign up](https://dashboard.nexmo.com/sign-up?icid=tryitfree_homepage_nexmodashbdfreetrialsignup_tile&utm_campaign=bizdirect&attribution_campaign=bizdirect&cjregion=429207&adobe_mc=MCMID%3D34270386780739068404296704713035591008%7CMCORGID%3DA8833BC75245AF9E0A490D4D%2540AdobeOrg%7CTS%3D1663266957) to use Vonage Communication APIs. On the Vonage API Dashboard, visit the **[API Settings](https://dashboard.nexmo.com/settings)** tab and copy the **API Key**, **API Secret**, and **Signature Secret** from there.

![Vonage API Dashboard](/images/blog/function-template-whatsapp-vonage/api-settings.avif)

After that, visit the **[Messages API Sandbox](https://dashboard.nexmo.com/messages/sandbox)** and enable **WhatsApp** to set up a test environment for our Appwrite Function template. Make sure to copy the **Phone Number** provided there (and join the WhatsApp channel with the instructions on the page. Once the Appwrite Function is deployed, we will return here to add the Function URL as the **Inbound Webhook** link.

![Vonage Messages API Sandbox](/images/blog/function-template-whatsapp-vonage/messages-sandbox.avif)

## Preparing the Appwrite Function

Now that we have all the necessary details from our Vonage account, let us get the function ready on **[Appwrite](https://cloud.appwrite.io)**. Head over to your Appwrite project and visit the **Functions** page. From there, we will select the **Templates** tab, search for and select the **WhatsApp with Vonage** function template.

![Functions Templates](/images/blog/function-template-whatsapp-vonage/templates.avif)

This function requires **4 environment variables** to setup:

- `VONAGE_API_KEY`: API Key from our Vonage account *(copied from the API Settings)*
- `VONAGE_API_SECRET`: API Secret from our Vonage account *(copied from the API Settings)*
- `VONAGE_API_SIGNATURE_SECRET`: Secret used to sign and verify the JWT token sent by Vonage *(copied from the API Settings)*
- `VONAGE_WHATSAPP_NUMBER`: Vonage WhatsApp number to send messages from *(copied from the Messages API Sandbox)*

After you have configured the environment variables, you must connect your Appwrite account with GitHub, select **Create a new repository** (this will generate a GitHub repository for you with the function), and leave the production branch and root settings as default to create this function.

Once the function is ready, visit the **Domains** tab on the function page and copy the domain to add to the **Inbound Webhook** link on the **Messages API Sandbox** page on your Vonage account.

![Function Domain](/images/blog/function-template-whatsapp-vonage/domains.avif)

## Testing the Function

Once all the aforementioned steps are complete, it is time to test the function!

Open the WhatsApp app on your phone, join the Vonage WhatsApp Channel (via the steps mentioned on the *Messaging API Sandbox* on *Vonage*), and send any message to the WhatsApp number. You shall receive a message in the format: `Hi there! You sent me: <SENT MESSAGE>`

![WhatsApp](/images/blog/function-template-whatsapp-vonage/whatsapp.avif)

## Next steps

We’ve covered the basics, and now it’s your time to shine! With a few changes, you should be able to extend this template to fit your app. Be sure to check out the other available Function Templates. We’ve created many that could be of use in your projects. You can find the [templates GitHub repository here](https://github.com/appwrite/templates).

For more information about Appwrite and Appwrite Functions:

- [Appwrite Function Docs](https://appwrite.io/docs/functions): These documents provide more information on how to use Appwrite Functions.
- [Functions Announcement](https://dev.to/appwrite/serverless-your-way-unleashing-appwrite-functions-true-potential-2l4f): Read the full announcement on Functions 1.4.
- [Appwrite Discord](https://discord.com/invite/appwrite): Connect with other developers and the Appwrite team for discussion, questions, and collaboration.