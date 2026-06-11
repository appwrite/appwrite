---
layout: tutorial
title: Create function
description: Add paid app subscription plans to your app with Stripe and Appwrite Functions.
step: 3
---

Head to the [Appwrite Console](https://cloud.appwrite.io/console) and create a new project if you haven't already. If this is your first time using Appwrite, you will be asked to sign up first.

### Create a new function {% #create-function %}
Once inside your project overview, switch to the **Functions** page from the left sidebar. Under the **Templates** section, use the search bar and look for `subscriptions`. You will find the **Subscriptions with Stripe** template, which you can use by clicking the **Create function** button.


{% only_dark %}
![Project settings screen](/images/docs/tutorials/subscriptions-with-stripe/dark/templates-stripe-subscription.avif)
{% /only_dark %}
{% only_light %}
![Project settings screen](/images/docs/tutorials/subscriptions-with-stripe/templates-stripe-subscription.avif)
{% /only_light %}

## Create a new function {% #create-function %}

You will be asked to configure Function's **Name**, **Runtime**, and **Function ID**. Feel free to customise those or leave them as is. Click the **Next** button to continue to the environment variables definition.

## Configure variables {% #configure-variables %}

Toggle **Generate API key on completion** in the `APPWRITE_API_KEY` variable, so you don't need to go and generate it manually. 

Fill the `STRIPE_SECRET_KEY` variable with the key you copied from Stripe's Dashboard under the **API keys** section. 

Similarly, fill the `STRIPE_WEBHOOK_SECRET` variable with the secret copied after creating **Webhook**. 

If you are self-hosting Appwrite, you will need to change `APPWRITE_ENDPOINT` as well, under the **Optional variables** section. 

When done, click the **Next** button.

## Connect repository {% #connect-repository %}

In this section, you can decide whether to host your Function's source code in an existing repository or create a new one. 

Larger projects will benefit from having all functions in one repository, but to keep this quick start simple, let's stick to **Create a new repository**.

Click the **Next** button to continue.

You will be asked to connect your project to Git provider. Click the one you want to use, and follow the authorization process. Once done, you will be redirected back to the Appwrite Console. Here, you can configure **Repository name** and toggle if you want to **Keep repository private**. Once done, click the **Next** button to continue to the final step.

## Select branch {% #select-branch %}

Finally, you can configure repository-related settings such as **Production branch**, **Root directory**, or **Silent mode**. Let's stick to the default values provided by the template and click the **Create** button to create the function.