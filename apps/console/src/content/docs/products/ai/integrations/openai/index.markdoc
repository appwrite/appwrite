---
layout: article
title: Integrating OpenAI
description: Learn how to integrate OpenAI into your Appwrite project.
difficulty: intermediate
readtime: 15
---

The OpenAI API is a powerful tool that can be used to generate text, images, and more. This tutorial will guide you through the process of setting up the OpenAI API and integrating it into your Appwrite project.

We'll create a simple function that takes a text prompt and generates a completion using OpenAI's GPT-3 model. Then, using Appwrite functions we'll create a user interface that allows users to input text and see the generated completion.

# Prerequisites {% #prerequisites %}

- An Appwrite Project
- An [OpenAI API Key](https://platform.openai.com/account/api-keys)

{% section #step-1 step=1 title="Create new function" %}
Head to the [Appwrite Console](https://cloud.appwrite.io/console) then click on **Functions** in the left sidebar and then click on the **Create Function** button.

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
1. In the **Variables** step, add the `OPENAI_API_KEY`, generate it [here](https://platform.openai.com/account/api-keys).
1. Follow the step-by-step wizard and create the function.
{% /section %}

{% section #step-2 step=2 title="Add OpenAI SDK" %}
Once the function is created, navigate to the freshly created repository and clone it to your local machine.

Install the `openai` package to simplify the process of interacting with the OpenAI API.

```bash
npm install openai
```
{% /section %}

{% section #step-3 step=3 title="Create utility function" %}
For this example, the function will be able to take both `GET` and `POST` requests.

For the `GET` request, return a static HTML page that will have a form to submit text to the API.
Meanwhile the `POST` request will send the text to the OpenAI API and return the generated text.

Write the code to return the static HTML page, to do this create a new `src/utils.js` file with the following code:

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
Write the `GET` request handler in the `src/main.js` file. This handler will return a static HTML page you'll create later.

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

If the method is `GET`, it returns the static HTML page.
{% /section %}

{% section #step-5 step=5 title="Create web page" %}
Create a HTML web page that the function will serve. Create a new file at `static/index.html` with some HTML boilerplate:

```html
<!doctype html>
  <html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>OpenAI Demo</title>

    <script>
      async function onSubmit(prompt) {
        const response = await fetch('/', {
          method: 'POST',
          body: JSON.stringify({ prompt }),
          headers: {
            'Content-Type': 'application/json',
          },
        });

        const json = await response.json();

        if (!json.ok || json.error) {
          alert(json.error);
        }

        return json.completion;
      }
    </script>

    <script src="//unpkg.com/alpinejs" defer></script>

    <link rel="stylesheet" href="https://unpkg.com/@appwrite.io/pink" />
    <link rel="stylesheet" href="https://unpkg.com/@appwrite.io/pink-icons" />
  </head>
</html>
```

The code above includes a script that will handle the form submission and a script tag that includes the Alpine.js library. This library will be used to handle the form submission.

After the `</head>` tag add a `<body>` containing the visible form:

```html
<body>
  <main class="main-content">
    <div class="top-cover u-padding-block-end-56">
      <div class="container">
        <div
          class="u-flex u-gap-16 u-flex-justify-center u-margin-block-start-16"
        >
          <h1 class="heading-level-1">Prompt ChatGPT Demo</h1>
          <code class="u-un-break-text"></code>
        </div>
        <p
          class="body-text-1 u-normal u-margin-block-start-8"
          style="max-width: 50rem"
        >
          Use this page to test your implementation with OpenAI ChatGPT. Enter
          text and receive the model output as a response.
        </p>
      </div>
    </div>
    <div
      class="container u-margin-block-start-negative-56"
      x-data="{ prompt: '', answer: '', loading: false }"
    >
      <div class="card u-flex u-gap-24 u-flex-vertical">
        <div class="u-flex u-cross-center u-gap-8">
          <div
            class="input-text-wrapper is-with-end-button u-width-full-line"
          >
            <input x-model="prompt" type="search" placeholder="Question" />
            <div class="icon-search" aria-hidden="true"></div>
          </div>

          <button
            class="button"
            x-bind:disabled="loading"
            x-on:click="async () => { loading = true; answer = ''; try { answer = await onSubmit(prompt) } catch(err) { console.error(err); } finally { loading = false; } }"
          >
            <span class="text">Submit</span>
          </button>
        </div>
        <template x-if="answer">
          <div class="u-flex u-flex-vertical u-gap-12">
            <div class="u-flex u-flex-vertical u-gap-12 card">
              <div class="u-flex u-gap-12">
                <h5 class="eyebrow-heading-2">ChatGPT:</h5>
              </div>

              <div style="overflow-x: hidden; line-break: anywhere">
                <p class="u-color-text-gray" x-text="answer"></p>
              </div>
            </div>
          </div>
        </template>
      </div>
    </div>
  </main>
</body>
```

The form will allows users to submit your text to the Appwrite function through a POST request. The Appwrite function will call the OpenAI API, and return the response to the user.
{% /section %}

{% section #step-6 step=6 title="Handle POST request" %}
Add methods necessary to integrate with the OpenAI API.

Import `openai` at the top of the `main.js` file.

```js
import { OpenAIApi, Configuration } from 'openai';
```

Add code to validate the body of the request and initialize the Appwrite SDK:

```js
const client = new Client()
  .setEndpoint(process.env.APPWRITE_ENDPOINT ?? "https://<REGION>.cloud.appwrite.io/v1")
  .setProject(process.env.APPWRITE_FUNCTION_PROJECT_ID)
  .setKey(process.env.APPWRITE_API_KEY);

if (!req.body.prompt && typeof req.body.prompt !== "string") {
  return res.json({ ok: false, error: "Missing required field `prompt`" }, 400);
}

const openai = new OpenAIApi(
  new Configuration({
    apiKey: process.env.OPENAI_API_KEY,
  })
);
```

Make a request to the OpenAI API and return the response:

```js
try {
  const response = await openai.createChatCompletion({
    model: 'gpt-3.5-turbo',
    max_tokens: parseInt(process.env.OPENAI_MAX_TOKENS ?? '512'),
    messages: [{ role: 'user', content: req.body.prompt }],
  });
  const completion = response.data.choices[0].message?.content;
  return res.json({ ok: true, completion }, 200);
} catch (err) {
  return res.json({ ok: false, error: 'Failed to query model.' }, 500);
}
```
{% /section %}

{% section #step-8 step=8 title="Test the function" %}
Now that the function is deployed, test it by visiting the function URL in your browser.
This should show the UI created earlier and to test it, write a prompt and click the submit button. After a brief moment you should see the generated text from the OpenAI API.
{% /section %}
