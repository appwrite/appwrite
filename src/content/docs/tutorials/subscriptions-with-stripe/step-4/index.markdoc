---
layout: tutorial
title: Configure web platform
description: Add paid app subscription plans to your app with Stripe and Appwrite Functions.
step: 4
---

## Add platform {% #add-platform %}
To showcase the functionality, the template ships with a demo frontend that you can use. To allow this demo, you must add Function's domain as a trusted web platform. Head to your project's **Overview** page in Apwrite Console, and scroll down to the **Integrations** section. Click the **Add platform** button and select **Web App** from the dropdown.

{% only_dark %}
![Add a platform](/images/docs/quick-starts/dark/add-platform.avif)
{% /only_dark %}
{% only_light %}
![Add a platform](/images/docs/quick-starts/add-platform.avif)
{% /only_light %}

Set **Name** to `Subscription demo` and **Hostname** to your Function's domain. 
You can find your function's domain under the **Domains** tab.

{% only_dark %}
![Functions domain](/images/docs/tutorials/subscriptions-with-stripe/dark/functions-stripe-domain.avif)
{% /only_dark %}
{% only_light %}
![Functions domain](/images/docs/tutorials/subscriptions-with-stripe/functions-stripe-domain.avif)
{% /only_light %}

Click the **Next** button to continue. 

You don't need to follow the remaining screens, so you can click on **Skip optional steps**.

## Update webhook endpoint {% #update-webhook-endpoint %}

Navigate to your Stripe dashboard, under **Developers**, go to the **Webhooks** tab.
Click on the webhook we added earlier, under the three-dot menu, click **Update details**.

Update the **Endpoint URL** to `https://<YOUR_FUNCTION_DOMAIN>/webhook`. For example, `https://6579ea96aa28a5cfb66a.fra.appwrite.run/webhook`