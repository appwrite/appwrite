---
layout: integration
title: Prompt Perplexity
description: Send text prompts to Perplexity and receive text generations
date: 2024-07-30
featured: false
isPartner: true
isNew: false
cover: /images/integrations/ai-perplexity/cover.avif
category: ai
product: 
    avatar: '/images/integrations/avatars/perplexity.avif'
    vendor: Perplexity
    description: 'Perplexity is an advanced AI tool that generates high-quality text based on given prompts.'
platform: 
    - 'Self-hosted'
    - 'Cloud'
images: 
    - /images/integrations/ai-perplexity/cover.avif
    - /images/integrations/ai-perplexity/api-key.avif
    - /images/integrations/ai-perplexity/template.avif
    - /images/integrations/ai-perplexity/variables.avif
    - /images/integrations/ai-perplexity/demo.avif
---

Perplexity is an advanced AI tool that generates high-quality text based on given prompts. It's designed to improve applications by providing sophisticated text analysis and generation capabilities.

# How does the integration work?

You can utilize a pre-built Appwrite function template to create a chatbot using Perplexity. This will allow you to send text prompts to the Perplexity API and receive generated text responses, enriching your app's functionality.

# How to implement

To implement the Perplexity integration, follow these simple steps:

## Step 1: Create a Perplexity account

First, [create an account on Perplexity](https://perplexity.ai), head to the [API Settings](https://www.perplexity.ai/settings/api), configure your credit card for payments, and generate an API key. Save this API key for further usage.

![API key](/images/integrations/ai-perplexity/api-key.avif)

## Step 2: Create the Appwrite Function

For this step, you must [create an account on Appwrite Cloud](https://cloud.appwrite.io/register) or [self-host Appwrite](https://appwrite.io/docs/advanced/self-hosting) if you haven’t already. In case you decide to self-host Appwrite, there are [additional setup steps](https://appwrite.io/docs/advanced/self-hosting/functions) to use Appwrite Function templates.

Head over to the Appwrite console, navigate to the **Functions** page, click on the **Templates** tab, and search for the **Perplexity** function template.

![Function template](/images/integrations/ai-perplexity/template.avif)

Add the **Perplexity API key** you saved in the **Variables** step. Then, create a new repository with the default branch and root directory settings. You can edit this repository later to update the function logic.

![Env variables](/images/integrations/ai-perplexity/variables.avif)

## Step 3: Test the function

Once the function is ready, visit the **Domains** tab on the **Functions** page and copy the domain URL to test the function. You can open this URL in your browser to test with our pre-built interface.

![Demo](/images/integrations/ai-perplexity/demo.avif)

You can also use tools like Postman, cURL, or your favorite programming language to send a POST (HTTP) request with a prompt and receive a response from the Perplexity API.

```bash
curl -X POST http://DEPLOYED_FUNCTION_DOMAIN \
-H "Content-Type: application/json" \
-d '{"prompt": "What is Appwrite?"}' 
```

# Read more about Perplexity and Appwrite Functions

If you would like to learn more about Perplexity and Appwrite Functions, we have some resources that you should visit:

- [Integrating Perplexity - Appwrite Docs](https://appwrite.io/docs/products/ai/integrations/perplexity)
- [More on Appwrite AI integrations](https://appwrite.io/blog/post/announcing-appwrite-new-ai-integrations)
- [Appwrite Functions docs](https://appwrite.io/docs/products/functions)