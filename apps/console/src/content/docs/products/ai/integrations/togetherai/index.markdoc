---
layout: article
title: Integrating Together AI
description: Learn how to integrate Together AI into your Appwrite project.
difficulty: intermediate
readtime: 15
---

Together AI is an AI as a Service provider that's powered by an industry-leading inference engine providing fast and cheap inference. The platform can generate text and images using leading open-source models such as LLaMA 3 and Stable Diffusion.

Integrating Together AI into your Appwrite project is simple. This tutorial will guide you through setting up the Together AI API and integrating it into your Appwrite project.

# Prerequisites {% #prerequisites %}

- An Appwrite Project
- An Appwrite Bucket
- A [Together AI API Key](https://docs.together.ai/reference/authentication-1)

{% section #step-1 step=1 title="Create new function" %}
Head to the [Appwrite Console](https://cloud.appwrite.io/console), click, on **Functions** in the left sidebar, and click, the **Create Function** button.

{% only_dark %}
![Create function screen](/images/docs/functions/dark/template.avif)
{% /only_dark %}

{% only_light %}
![Create function screen](/images/docs/functions/template.avif)
{% /only_light %}

1. In the Appwrite Console's sidebar, click **Functions**.
1. Click **Create function**.
1. Under **Connect Git repository**, select your provider.
1. After connecting to GitHub, under **Quick start**, select the **Node.js** starter template.
1. In the **Variables** step, add `APPWRITE_BUCKET_ID` and `TOGETHER_API_KEY`. You can generate your Together AI key [here](https://api.together.xyz/settings/api-keys). For the `APPWRITE_API_KEY`, tick the box to **Generate API key on completion**..
1. Follow the step-by-step wizard and create the function.
{% /section %}

{% section #step-2 step=2 title="Add Undici" %}
Once the function is created, clone and open it in your development environment.

Once inside the cloned function, install `undici` ( an HTTP client ) to interact with Together AI's API.

```
npm install undici
```
{% /section %}

{% section #step-3 step=3 title="Create utility function" %}
For this example, the function will be able to handle both `GET` and `POST` requests.

For the `GET` request, return a static HTML landing page, that will use AlpineJS to make a `POST` request to our function.
Meanwhile, the `POST` request will use fetch to make a request to the Together AI API.

In preparation for the `GET` request handler, create a new `src/utils.js` file with some utility functions:

```js
import path from 'path';
import { fileURLToPath } from 'url';
import fs from 'fs';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const staticFolder = path.join(__dirname, '../static');

export function getStaticFile(fileName) {
  return fs.readFileSync(path.join(staticFolder, fileName)).toString();
}
```
{% /section %}

{% section #step-4 step=4 title="Handle GET request" %}
Write the `GET` request handler in the `src/main.js` file. This handler will return a static HTML page, which will be created in the next section.

```js
import { getStaticFile } from './utils.js';

export default async ({ req, res, error }) => {
  if (req.method === 'GET') {
    return res.text(getStaticFile('index.html'), 200, {
      'Content-Type': 'text/html; charset=utf-8',
    });
  }
};
```
{% /section %}

{% section #step-5 step=5 title="Create static page" %}
Create the static HTML page that the function will serve. Create a new file at `static/index.html` with some HTML boilerplate:

```html
<!doctype html>
<html lang="en">
</html>
```

Within the `<html>` tag, add a `<head>` tag that will define the style and scripts.

```html
<head>
  <meta charset="UTF-8" />
  <meta http-equiv="X-UA-Compatible" content="IE=edge" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Together AI Demo</title>

  <script>
    async function onSubmit(prompt, type) {
      const response = await fetch('/', {
        method: 'POST',
        body: JSON.stringify({ prompt, type }),
        headers: {
          'Content-Type': 'application/json',
        },
      });

      const json = await response.json();

      if (!json.ok || json.error) {
        alert(json.error);
      }

      return JSON;
    }
  </script>

  <script src="//unpkg.com/alpinejs" defer></script>

  <link rel="stylesheet" href="https://unpkg.com/@appwrite.io/pink" />
  <link rel="stylesheet" href="https://unpkg.com/@appwrite.io/pink-icons" />
</head>
```

And after the `</head>` tag add a `<body>` which will contain the actual form:

```html
<body>
  <main class="main-content">
    <div class="top-cover u-padding-block-end-56">
      <div class="container">
        <div class="u-flex u-gap-16 u-flex-justify-center u-margin-block-start-16">
          <h1 class="heading-level-1">Together AI Demo</h1>
          <code class="u-un-break-text"></code>
        </div>
        <p class="body-text-1 u-normal u-margin-block-start-8" style="max-width: 50rem">
          Use this page to test your implementation with Together AI. Enter
          text and receive the model output as a response.
        </p>
      </div>
    </div>
    <div class="container u-margin-block-start-negative-56"
      x-data="{ type: 'text', prompt: '', answer: {type: '', answer: ''}, loading: false }">
      <div class="card u-flex u-gap-24 u-flex-vertical">
        <div class="u-flex u-cross-center u-gap-8">
          <div class="input-text-wrapper is-with-end-button u-width-full-line">
            <input x-model="prompt" type="search" placeholder="Prompt" />
            <div class="icon-search" aria-hidden="true"></div>
          </div>
          <div class="select u-width-140">
            <select x-model="type">
              <option value="text">Text</option>
              <option value="image">Image</option>
            </select>
            <span class="icon-cheveron-down" aria-hidden="true"></span>
          </div>

          <button class="button" x-bind:disabled="loading"
            x-on:click="async () => { loading = true; answer = {type: '', answer: ''}; try { answer = await onSubmit(prompt, type) } catch(err) { console.error(err); } finally { loading = false; } }">
            <span class="text">Generate</span>
          </button>
        </div>
        <template x-if="answer.type">
          <div class="u-flex u-flex-vertical u-gap-12">
            <div class="u-flex u-flex-vertical u-gap-12 card">
              <div class="u-flex u-gap-12">
                <h5 class="eyebrow-heading-2">Result:</h5>
              </div>
              <template x-if="answer.type === 'image'" class="u-flex u-gap-12">
                <img class="u-max-width-400" x-bind:src="answer.response" alt="Together output" />
              </template>
              <template x-if="answer.type === 'text'" class="u-flex u-gap-12">
                <p class="u-color-text-gray" x-text="answer.response"></p>
              </template>
            </div>
          </div>
        </template>
      </div>
    </div>
  </main>
</body>
```

Together, this will render a form to submit a query to the Appwrite Function through a `POST` request. The Appwrite Function invokes Together AI's API and returns the response, which will be displayed on the page using different conditional statements depending on the output media type.
{% /section %}

{% section #step-6 step=6 title="Handle POST Request" %}
Now that you're serving a basic HTML page add methods necessary to integrate with Together AI's API. Import `fetch` from `undici` and the Appwrite SDK at the top of `src/main.js`.

```js
import { fetch } from 'undici'
import { Client, ID, Storage } from 'node-appwrite';
import { InputFile } from 'node-appwrite/file';
```

To handle the `POST` request, add the following code to the end of the request handler in the `src/main.js` file to validate the request body and define the models:

```js
const models = {
  'text': 'mistralai/Mixtral-8x7B-Instruct-v0.1',
  'image': 'stabilityai/stable-diffusion-xl-base-1.0'
};

if (!req.body.prompt || typeof req.body.prompt !== 'string') {
  return res.json({ ok: false, error: 'Missing required field `prompt`' }, 400);
}

if (req.body.type !== 'text' && req.body.type !== 'image') {
  return res.json({ ok: false, error: 'Invalid field `type`' }, 400);
}
```

In this example, you will use Mistral's `Mixtral 8x7B` for text generation and StabilityAI's `Stable Diffusion XL` for image generation. You can find more models on [Together AI's docs](https://docs.together.ai/docs/inference-models).

Next, following the previous code dd some per-model configurations:

```js
let request = {
  model: models[req.body.type],
};

switch (req.body.type) {
  case 'text':
    request = {
      ...request,
      messages: [
        {
          role: "system",
          content: "You are a helpful assistant"
        },
        {
          role: "user",
          content: req.body.prompt
        }
      ],
      max_tokens: 512,
      repetition_penalty: 1,
    }
  break;
  case 'image':
    request = {
      ...request,
      prompt: req.body.prompt,
      width: 512,
      height: 512,
      steps: 20,
      results: 1,
      negative_prompt: "deformed, noisy, blurry, distorted",
    }
  break;
};
```

This allows you to configure each of the models you use individually. Feel free to play with this configuration to get the best results for your use case. Finally, with the request built, you can call the Together AI API and generate a prediction:

```js
let response;
let url = 'https://api.together.xyz/v1/completions';

if (req.body.type === 'text') {
  url = 'https://api.together.xyz/v1/chat/completions'
};

try {
  response = await fetch(URL, {
    headers: {
      "content-type": "application/json",
      "Authorization": `Bearer ${process.env.TOGETHER_API_KEY}`
    },
    method: 'POST',
    body: JSON.stringify(request)
  })
} catch (err) {
  error(err);
  return res.json({ ok: false, error: 'Failed to run model' }, 500);
}

let resJson = await response.json();

// Upload image to Appwrite Storage and return URL
if (req.body.type === 'image') {
  const endpoint = process.env.APPWRITE_ENDPOINT || 'https://<REGION>.cloud.appwrite.io/v1'

  const client = new Client()
    .setEndpoint(endpoint)
    .setKey(process.env.APPWRITE_API_KEY)
    .setProject(process.env.APPWRITE_FUNCTION_PROJECT_ID)

  const storage = new Storage(client);

  let data = Buffer.from(resJson.choices[0].image_base64, 'base64');

  let file = await storage.createFile({
    bucketId: process.env.APPWRITE_BUCKET_ID,
    fileId: ID.unique(),
    file: InputFile.fromBuffer(data, "image.png")
  });

  return res.json({
    ok: true,
    type: req.body.type,
    response: `${endpoint}/storage/buckets/${process.env.APPWRITE_BUCKET_ID}/files/${file["$id"]}/view?project=${process.env.APPWRITE_FUNCTION_PROJECT_ID}`
  })
}

return res.json({ ok: true, type: req.body.type, response: resJson.choices[0].message.content}, 200);
```

This code sends the prompt to the Together AI API and returns the response to the user.
The function also uploads any images generated to a bucket and returns the URL to the user.
Any errors encountered during the process are caught and reported for easy debugging.

The function can now be deployed to Appwrite by pushing the changes to your repository.
{% /section %}

{% section #step-7 step=7 title="Test the function" %}
Now that the function is deployed test it by visiting the function URL in your browser.
This should show the UI created earlier. To test it, write a prompt and click the submit button. After a brief moment, you should see the results.

![Testing the function](/images/docs/ai/integrations/together/demo.avif)
{% /section %}
