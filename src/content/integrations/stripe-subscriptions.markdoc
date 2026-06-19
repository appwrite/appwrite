---
layout: integration
title: Subscriptions with Stripe
description: Integrate Stripe subscriptions with Appwrite to accept and manage recurring payments from your customers.
date: 2024-07-30
featured: false
isPartner: true
isNew: false
cover: /images/integrations/stripe-subscriptions/cover.avif
category: payments
product: 
    avatar: '/images/integrations/avatars/stripe.avif'
    vendor: Stripe
    description: 'Stripe is an online payment processing platform that allows businesses to accept online payments securely and efficiently.'
platform: 
    - 'Self-hosted'
    - 'Cloud'
images: 
    - /images/integrations/stripe-subscriptions/cover.avif
    - /images/integrations/stripe-subscriptions/dashboard.avif
    - /images/integrations/stripe-subscriptions/api-keys.avif
    - /images/integrations/stripe-subscriptions/webhooks.avif
    - /images/integrations/stripe-subscriptions/template.avif
    - /images/integrations/stripe-subscriptions/variables.avif
    - /images/integrations/stripe-subscriptions/web-platform.avif
    - /images/integrations/stripe-subscriptions/demo.avif
    - /images/integrations/stripe-subscriptions/user.avif
---

Stripe is an online payment processing platform that allows businesses to accept online payments securely and efficiently. Stripe supports a variety of payment methods, including credit cards, debit cards, and digital wallets, and offers additional services such as billing, invoicing, and fraud prevention.

With robust APIs and extensive documentation, Stripe enables developers to integrate its payment solutions into websites and mobile applications with ease, making it a popular choice for businesses of all sizes.

# How does the integration work?

You can utilize a pre-built Appwrite function template to enable paid subscriptions via Stripe in your app. This will allow you to accept recurring payments from your customers and grant them extra permissions.

# How to implement

To implement the Stripe subscriptions integration, there are several steps you must complete:

## Step 1: Setup Stripe

First, [sign up for Stripe](https://stripe.com/) and open the dashboard in test mode.

![Stripe dashboard](/images/integrations/stripe-subscriptions/dashboard.avif)

Head to the **Developers** page from the navbar and click on the **API keys** tab. Save the **Secret key** for further usage.

![Stripe API keys](/images/integrations/stripe-subscriptions/api-keys.avif)

Then, go to the **Webhooks** tab, select the events `customer.subscription.created` and `customer.subscription.deleted`, and add a temporary endpoint `https://temporary-endpoint` (we will replace this with our final endpoint later). Save the **webhook signing secret** for later usage.

![Stripe webhooks](/images/integrations/stripe-subscriptions/webhooks.avif)

## Step 2: Create the Appwrite Function

For this step, you must [create an account on Appwrite Cloud](https://cloud.appwrite.io/register) or [self-host Appwrite](https://appwrite.io/docs/advanced/self-hosting) if you haven’t already. If you decide to self-host Appwrite, there are [additional setup steps](https://appwrite.io/docs/advanced/self-hosting/functions) to use Appwrite Function templates.

Head over to the Appwrite console, navigate to the **Functions** page, click the **Templates** tab, and search for the **Subscriptions with Stripe** function template.

![Function template](/images/integrations/stripe-subscriptions/template.avif)

During the setup process, click the checkbox to generate an Appwrite API key on completion and add the **Stripe secret key** and **Webhook secret** in the **Variables** step. If you are self-hosting Appwrite, click the **optional variables** dropdown and update the Appwrite endpoint to your instance’s publicly accessible endpoint. 

![Env variables](/images/integrations/stripe-subscriptions/variables.avif)

Then, create a new repository with the default branch and root directory settings. You can edit this repository later to update the function logic. Once the function is deployed, go to the **Domains** tab on the **Function** page, copy the domain, and update it in your **Stripe webhook details** in the following format:

```bash
https://DEPLOYED_FUNCTION_DOMAIN/webhook
```

## Step 3: Test the function

Once all the steps are complete, it is time to test the function! First, copy the function URL, go to the **Appwrite project dashboard**, and add it to the **Platforms** section as a web app. This will register your function URL as an authorized hostname to interact with Appwrite (to prevent CORS).

![Add web platform](/images/integrations/stripe-subscriptions/web-platform.avif)

Then, open it in your browser to test the function and access the pre-built interactive UI. You can anonymously log in through this UI and create a subscription using [Stripe’s test card number](https://docs.stripe.com/testing#cards). As soon as this process is complete, the function UI will show that you are subscribed. 

![Demo](/images/integrations/stripe-subscriptions/demo.avif)

Additionally, your user on Appwrite will feature the `subscriber` label, which you can verify by visiting the **Auth** page in your Appwrite project and clicking on the user.

![Appwrite user](/images/integrations/stripe-subscriptions/user.avif)

# Read more about Stripe Subscriptions and Appwrite Functions

If you would like to learn more about Stripe Subscriptions and Appwrite Functions, we have some resources that you should visit:

- [Sign up on Stripe](https://stripe.com)
- [Add app subscriptions with Stripe on Appwrite](https://appwrite.io/docs/tutorials/subscriptions-with-stripe/step-1)
- [Learn more about Functions templates in Appwrite docs](https://appwrite.io/docs/products/functions/templates)