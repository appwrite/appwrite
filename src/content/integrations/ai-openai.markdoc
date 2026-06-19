---
layout: integration
title: Prompt ChatGPT
description: Send text prompts to OpenAI GPT-3.5 and receive text generations
date: 2024-07-30
featured: false
isPartner: true
isNew: false
cover: /images/integrations/ai-openai/cover.avif
category: ai
product: 
    avatar: '/images/integrations/avatars/openai.avif'
    vendor: OpenAI
    description: 'The OpenAI API allows you to integrate advanced AI models into your app.'
platform: 
    - 'Self-hosted'
    - 'Cloud'
images: 
    - /images/integrations/ai-openai/cover.avif
    - /images/integrations/ai-openai/api-key.avif
    - /images/integrations/ai-openai/template.avif
    - /images/integrations/ai-openai/variables.avif
    - /images/integrations/ai-openai/demo.avif
---

The OpenAI API allows you to integrate advanced AI models into your app. With the API, you can access powerful language models like GPT-3.5, GPT-4, and GPT-4o, which can perform a variety of tasks, including text generation, translation, summarization, and even coding assistance.

The API is designed to be user-friendly, providing endpoints that can be easily accessed through HTTP requests. OpenAI also provides detailed documentation and examples to help users get started and make the most of the API.

# How does the integration work?

Appwrite integrates OpenAI’s API to provide you with access to powerful text generation out-of-the-box with prospects to implement other features such as image creation and language translations.

# How to implement

To implement the OpenAI, there are several steps you must complete:

## Step 1: Create an Open AI account

First, [create an account on the OpenAI platform](https://platform.openai.com/) and set up your default project. Visit the [**API keys**](https://platform.openai.com/api-keys) page to create your secret key. Save this key for later usage.

![API key](/images/integrations/ai-openai/api-key.avif)

## Step 2: Create the Appwrite Function

For this step, you must [create an account on Appwrite Cloud](https://cloud.appwrite.io/register) or [self-host Appwrite](https://appwrite.io/docs/advanced/self-hosting) if you haven’t already. In case you decide to self-host Appwrite, there are [additional setup steps](https://appwrite.io/docs/advanced/self-hosting/functions) to use Appwrite Function templates.

Head over to the Appwrite console, navigate to the **Functions** page, click on the **Templates** tab, and search for the **ChatGPT** function template.

![Function template](/images/integrations/ai-openai/template.avif)

Add the **OpenAI API key** you saved in the **Variables** step. Then, create a new repository with the default branch and root directory settings. You can edit this repository later to update the function logic.

![Env variables](/images/integrations/ai-openai/variables.avif)

## Step 3: Test the function

Once the function is ready, visit the **Domains** tab on the **Functions** page and copy the domain URL to test the function. You can open this URL in your browser to test with our pre-built interface.

![Demo](/images/integrations/ai-openai/demo.avif)

You can also use tools like Postman, cURL, or your favorite programming language to send a POST (HTTP) request with a prompt and receive a response from the Perplexity API.

```bash
curl -X POST http://DEPLOYED_FUNCTION_DOMAIN \
-H "Content-Type: application/json" \
-d '{"prompt": "What is Appwrite?"}' 
```

# Read more about OpenAI and Appwrite Functions

If you would like to learn more about Open AI, visit these resources:

- [Create an OpenAI developer account](https://platform.openai.com/docs/quickstart)
- [Build an intelligent chatbot with ChatGPT and Appwrite Functions](https://appwrite.io/blog/post/function-template-prompt-chatgpt)
- [Integrating OpenAI - Appwrite Docs](https://appwrite.io/docs/products/ai/integrations/openai)
- [Appwrite Functions docs](https://appwrite.io/docs/products/functions)