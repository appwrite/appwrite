---
layout: post
title: Build an intelligent chatbot with ChatGPT and Appwrite Functions
description: Learn how to use an Appwrite Function Template to create an interactive bot using GPT-3.5.
date: 2023-11-15
cover: /images/blog/function-template-prompt-chatgpt/cover.avif
timeToRead: 5
author: aditya-oberai
category: tutorial
faqs:
  - question: "What is an Appwrite Function template?"
    answer: "Function templates are prebuilt Appwrite Functions you can install in your project with a few clicks. They cover common integrations like ChatGPT, Discord notifications, and Stripe webhooks, so you do not have to write the boilerplate yourself. See [Appwrite Functions](/docs/products/functions) for the product overview."
  - question: "Do I need to write any code to use the Prompt ChatGPT template?"
    answer: "No code is required to get the template running. You configure the OpenAI API key as an environment variable, connect a GitHub repo, and Appwrite handles the rest. You only need to write code if you want to customize the prompt logic or response format."
  - question: "Which OpenAI model does this template use?"
    answer: "The template in this tutorial uses GPT-3.5 by default for cost and latency reasons. You can switch the model name in the code to GPT-4 or any other model you have access to. The Appwrite Function wrapper does not depend on the specific model."
  - question: "How do I call the function from my app?"
    answer: "After deploying, copy the function domain URL from the Domains tab and send it a POST request with a JSON body containing your prompt. You can call it from any frontend framework, mobile app, or server using HTTP, or use the Appwrite SDK for a typed integration."
  - question: "How do I keep my OpenAI API key secure?"
    answer: "Store it only as an Appwrite Function environment variable, never in client code. Appwrite encrypts these variables at rest and only injects them into the function runtime, so the key never leaves the server. Avoid logging the key in your function output."
  - question: "Can I customize the system prompt or response format?"
    answer: "Yes. The template is fully open source, so you can fork the generated GitHub repository and modify the prompt construction or the JSON response shape. Push your changes and Appwrite redeploys the function automatically."
---

Function templates are pre-built Appwrite Functions that can be integrated into your Appwrite project with just a few clicks. Using them, you can easily incorporate new features and integrations into your app without writing additional code or managing infrastructure.

One such integration you can implement using Appwrite Functions is an **intelligent chatbot** using **ChatGPT**. In this blog, you will learn how you can use an Appwrite Function Template to create an interactive bot that will answer any of your questions using OpenAI’s GPT-3.5 API.

## Setting up the OpenAI Platform

To get an OpenAI API Key, you must create an account on the [OpenAI platform](https://platform.openai.com/). Once your account is set up, visit their [API keys](https://platform.openai.com/account/api-keys) page and create an **API Key**. Ensure you copy and save this key in a safe place, as the OpenAI platform will not let you view the key after it is created.

![OpenAI Platform](/images/blog/function-template-prompt-chatgpt/openai-api-keys.avif)

## Preparing the Appwrite Function

Now that we have our OpenAI API Key, let us get the function ready on **[Appwrite](https://cloud.appwrite.io)**. Head over to your Appwrite project and visit the **Functions** page. From there, we will select the **Templates** tab, search for and select the **Prompt ChatGPT** function template.

![Function Templates](/images/blog/function-template-prompt-chatgpt/templates.avif)

This function requires **1 environment variable** to setup:

- `OPENAI_API_KEY`: API Key from our OpenAI account

After you have configured the environment variables, you must connect your Appwrite account with GitHub, select **Create a new repository** (this will generate a GitHub repository for you with the function), and leave the production branch and root settings as default to create this function.

Once the function is ready, visit the **Domains** tab on the function page and copy the domain URL to test the function.

![Function Domain](/images/blog/function-template-prompt-chatgpt/domains.avif)

## Testing the Function

Once all the aforementioned steps are complete, it is time to test the function!

To consume this function, you must send the function URL a `POST` HTTP Request with a **JSON Body** in the following format:

```json
{
	"prompt": "<ENTER YOUR PROMPT>"
}
```

If successful, you will receive a response in the following format:

```json
{
    "ok": true,
    "completion": "<CHAT COMPLETION FROM GPT-3.5>"
}
```

Here’s a screenshot of a test prompt that was sent to the function using Postman:

![Postman](/images/blog/function-template-prompt-chatgpt/postman.avif)

## Next steps

We’ve covered the basics, and now it’s your time to shine! With a few changes, you should be able to extend this template to fit your app. Be sure to check out the other available Function Templates. We’ve created many that could be of use in your projects. You can find the [templates GitHub repository here](https://github.com/appwrite/templates).

For more information about Appwrite and Appwrite Functions:

- [Appwrite Function Docs](https://appwrite.io/docs/functions): These documents provide more information on how to use Appwrite Functions.
- [Functions Announcement](https://dev.to/appwrite/serverless-your-way-unleashing-appwrite-functions-true-potential-2l4f): Read the full announcement on Functions 1.4.
- [Appwrite Discord](https://discord.com/invite/appwrite): Connect with other developers and the Appwrite team for discussion, questions, and collaboration.