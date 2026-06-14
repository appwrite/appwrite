---
layout: post
title: Chat with your favorite fictional character using OpenAI and Appwrite Functions
description: Learn how you can use Appwrite Functions and OpenAI to chat with popular characters such as Batman.
date: 2024-04-04
cover: /images/blog/function-chat-fictional-character/cover.avif
timeToRead: 6
author: aditya-oberai
category: tutorial
faqs:
  - question: "What is an Appwrite Function?"
    answer: "An Appwrite Function is a serverless function that runs on Appwrite's infrastructure, triggered by HTTP requests, events, schedules, or the SDKs. They are useful for backend logic you do not want in your client app, like calling external APIs with secrets. See [Appwrite Functions](/docs/products/functions) for details."
  - question: "Do I need an OpenAI API key for this tutorial?"
    answer: "Yes. The function calls OpenAI's GPT-4 API, so you need a paid OpenAI account with an API key. To access GPT-4 specifically you also need to reach Usage tier 1, which OpenAI grants after a small amount of paid usage."
  - question: "How do I keep my OpenAI API key secret?"
    answer: "Store it as an environment variable on the Appwrite Function and never bake it into client code. Appwrite encrypts function environment variables at rest, and they are only injected into the function runtime, so the key stays off the client. See the [Functions environment variables docs](/docs/products/functions) for details."
  - question: "Can I swap GPT-4 for a different model or provider?"
    answer: "Yes. The function just calls a chat completion API, so you can switch to GPT-3.5, Claude, Gemini, or a self hosted model with minimal changes. You only need to update the SDK and the prompt format. The Appwrite Function wrapper itself does not care which model you use."
  - question: "How do I prevent the AI from breaking character?"
    answer: "Add explicit constraints in the additionalPrompt field, similar to a system prompt. For example, tell the model never to acknowledge that it is an AI and to stay in character even if asked directly. Combine this with low temperature settings for more consistent behavior."
  - question: "Can I trigger this function from a mobile or web app?"
    answer: "Yes. Appwrite Functions can be called directly from any client SDK, including Flutter, React Native, web, iOS, and Android. You can also expose them through a public domain for HTTP requests. This makes it straightforward to wire the chat function up to a UI in any framework."
---

Have you ever wondered what it would feel like to interact with your favorite fictional characters, such as Superman, Hermione Granger, Gandalf, or Snow White? As a part of an internal hackathon at Appwrite recently, my team developed an Appwrite Function that you can use to chat with any popular fictional character you like (we really wanted to talk to Batman!)

In this blog, let’s learn how you can build this Appwrite Function using OpenAI’s GPT-4 API.

![Prototype of Bruce Wayne chat](/images/blog/function-chat-fictional-character/prototype.avif)

# Setting up the OpenAI platform

To develop this project, you first need an OpenAI API Key, for which you must create an account on the [OpenAI platform](https://platform.openai.com/). Once your account is set up, visit their [API keys](https://platform.openai.com/account/api-keys) page and create an API Key. Ensure you copy and save this key in a safe place, as the OpenAI platform will not let you view the key after it is created.

![OpenAI API Keys](/images/blog/function-chat-fictional-character/openai.avif)

> Note: To use the GPT-4 API, your account must be upgraded to the Usage tier 1. To learn more, visit their [Usage tiers documentation](https://platform.openai.com/docs/guides/rate-limits/usage-tiers?context=tier-one).

# Initializing the Appwrite Function

Now that we have our OpenAI API Key, let us get the function ready on [Appwrite](https://cloud.appwrite.io/). Head over to your Appwrite project and visit the Functions page. From there, we will use the Node.js starter template and create a function.

![Appwrite Functions](/images/blog/function-chat-fictional-character/functions.avif)

Once the function is ready, we must visit the Settings tab on the Function page and add the following environment variables:

- `OPENAI_API_KEY`: API Key from our OpenAI account
- `OPENAI_MAX_TOKENS`: Maximum number of tokens that the OpenAI response should contain (we’ll set this as `512`)

Once that is done, visit the function’s GitHub repository and clone the project.

# Developing the function logic

To develop the function, we must first install the `openai` npm package. Open your terminal in the project directory and run the following command:

```bash
npm i openai
```

Once that is done, visit the `src/main.js` file and replace the entire code with the following:

```js
import { OpenAI } from 'openai';

export default async ({ req, res, log, error }) => {
  try {
    const openai = new OpenAI({
      apiKey: process.env.OPENAI_API_KEY,
    });

    var prompt = `You are ${req.body.character}.\nRespond to the following question in first-person: ${req.body.question}\n${req.body.additionalPrompt}`

    const response = await openai.chat.completions.create({
      model: 'gpt-4',
      max_tokens: parseInt(process.env.OPENAI_MAX_TOKENS ?? '512'),
      messages: [{ role: 'user', content: prompt }],
    });
    const completion = response.choices[0].message?.content;
    log(completion);
    return res.json({ ok: true, answer: completion }, 200);
  } catch (err) {
    error(err.message);
    return res.json({ ok: false, error: err.message }, 500);
  }
};
```

This function will accept the name of a character, the question from a user, and any additional prompt you might optionally like to give. For example, in our hackathon project, we wanted to interact with Bruce Wayne and ensure that his Batman alter-ego was not directly given away, so here’s what our inputs looked like:

| Character name | Question | Additional prompt |
| --- | --- | --- |
| Bruce Wayne | Are you Batman? | Ensure that you don't reveal your Batman alter-ego but you can tip-toe around it. |

# Testing the function

Once you’ve completed all the aforementioned steps, you can push the code to the generated GitHub repository, at which point Appwrite Cloud will automatically deploy the changes to your function.

You can test the function by sending it a cURL request from your terminal or any other API testing client.

```bash
curl --location '<YOUR_FUNCTION_URL>' \
--header 'Content-Type: application/json' \
--data '{
  "character": "Bruce Wayne",
  "question": "Are you Batman?",
  "additionalPrompt": "Ensure that you don'\''t reveal your Batman alter-ego but you can tip-toe around it."
}'
```

![Thunder Client on VS Code](/images/blog/function-chat-fictional-character/http.avif)

# Next steps

And with that, our fictional character chat function is ready! If you liked this project and/or want to investigate the function code, visit the [GitHub repository](https://github.com/adityaoberai/CharacterChat).

For more information about Appwrite Functions, visit the following resources:

- [Appwrite Function Docs](https://appwrite.io/docs/functions): These documents provide more information on how to use Appwrite Functions.
- [Appwrite Discord](https://discord.com/invite/appwrite): Connect with other developers and the Appwrite team for discussion, questions, and collaboration.