---
layout: tutorial
title: Setup Stripe
description: Add paid app subscription plans to your app with Stripe and Appwrite Functions.
step: 2
---

Start by visiting [Stripe](https://stripe.com/) and creating an account. When successful, you will see Stripe Dashboard.

This quick start will use test mode, but the same steps will also work for live mode.

{% only_dark %}
![Stripe dashboard](/images/docs/tutorials/subscriptions-with-stripe/dark/stripe-dashboard.avif)
{% /only_dark %}
{% only_light %}
![Stripe dashboard](/images/docs/tutorials/subscriptions-with-stripe/stripe-dashboard.avif)
{% /only_light %}

## API key {% #api-key %}

In the header, you can switch to the **Developers** page, where you can head to the **API Keys** section. On this page, reveal and copy the **Secret key**. Note it down, as you will need it later when setting the `STRIPE_SECRET_KEY` environment variable.

{% only_dark %}
![Stripe API key screen](/images/docs/tutorials/subscriptions-with-stripe/dark/stripe-api-key.avif)
{% /only_dark %}
{% only_light %}
![Stripe API key screen](/images/docs/tutorials/subscriptions-with-stripe/stripe-api-key.avif)
{% /only_light %}

## Webhooks {% #webhooks %}

Go to the **Webhooks** section and click the button to **Add an endpoint**.

Let's add a fake endpoint for now in order to get a webhook secret. Later, when you set up the Appwrite Function, you will update this endpoint with the Function's endpoint.

Enter `https://temporary-endpoint/` as **Endpoint URL** and feel free to write some **Description** for yourself. 
Leave everything else as is, and continue down to **Select events**. 
You need to toggle events `customer.subscription.created` and `customer.subscription.deleted`. 
You can use the search bar to your advantage. By searching for `subscription`, you should easily find those two events. 

Once both are selected, you can click a button to **Add events**. Finish off the form by clicking the **Add endpoint** button.


{% only_dark %}
![Stripe webhook setup screen](/images/docs/tutorials/subscriptions-with-stripe/dark/stripe-webhook-subscription.avif)
{% /only_dark %}
{% only_light %}
![Stripe webhook setup screen](/images/docs/tutorials/subscriptions-with-stripe/stripe-webhook-subscription.avif)
{% /only_light %}

Once created, click **Reveal** under **Signing secret** and copy the secret. Note it down, as you will need it later when setting the `STRIPE_WEBHOOK_SECRET` environment variable.

Keep this page open, as you will need it soon to update the endpoint with the real Function's endpoint.