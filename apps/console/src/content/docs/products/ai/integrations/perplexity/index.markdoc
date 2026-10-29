---
layout: article
title: Integrating Perplexity
description: Learn how to integrate the Perplexity API into your Appwrite project.
difficulty: intermediate
readtime: 15
---

Integrating Perplexity into your Appwrite project is simple. This tutorial will guide you through the process of setting up the Perplexity API and integrating it into your Appwrite project.

# Prerequisites {% #prerequisites %}

- An Appwrite Project
- A [Perplexity API Key](https://docs.perplexity.ai/docs/getting-started)

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
1. In the **Variables** step, add the `PERPLEXITY_API_KEY`, generate it [here](https://docs.perplexity.ai/docs/getting-started).
1. Follow the step-by-step wizard and create the function.
{% /section %}

{% section #step-2 step=2 title="Add OpenAI SDK" %}
Once the function is created, clone the function and open it in your development environment.

The Perplexity API is compatible with the OpenAI SDK, so we can use the OpenAI SDK to interact with Perplexity.
Once you have the repository open, install the OpenAI SDK by running the following command in your terminal:

```bash
npm install openai
```

Perplexity's API is OpenAI compatible, so we can use the OpenAI SDK to interact with Perplexity.
{% /section %}

{% section #step-3 step=3 title="Create utility function" %}
For our example, our function will be able to take both `GET` and `POST` requests.

The function will return a web page on `GET` requests and return a response from Perplexity on `POST` requests.

To begin with we will write the code to return the static HTML page.
Create a new `src/utils.js` file with the following code:

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
We're going to write our `GET` request handler in the `src/main.js` file. This handler will return a static HTML page we'll create later.

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
Create the static HTML page that our function will serve. Create a new file at `static/index.html` with some HTML boilerplate:

```html
<!doctype html>
<html lang="en">
</html>
```

Within the `<html>` tag, we're going to add a `<head>` tag that will define our style and scripts.

```html
<head>
  <meta charset="UTF-8" />
  <meta http-equiv="X-UA-Compatible" content="IE=edge" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Perplexity AI Demo</title>

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
```

And after the `</head>` tag we're going to add our `<body>` which will contain the actual form:

```html
<body>
  <main class="main-content">
    <div class="top-cover u-padding-block-end-56">
      <div class="container">
        <div
          class="u-flex u-gap-16 u-flex-justify-center u-margin-block-start-16"
        >
          <h1 class="heading-level-1">Perplexity AI Demo</h1>
          <code class="u-un-break-text"></code>
        </div>
        <p
          class="body-text-1 u-normal u-margin-block-start-8"
          style="max-width: 50rem"
        >
          Use this page to test your implementation with Perplexity AI. Enter
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
                <h5 class="eyebrow-heading-2">Perplexity AI:</h5>
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

All of this together will render a form that will submit your question to the Appwrite Function through a POST request which we'll create next. The Appwrite Function will call Perplexity's API and return the response, which will be displayed on your page.
{% /section %}

{% section #step-6 step=6 title="Handle POST Request" %}
Now that we're serving a basic HTML page, we can add methods necessary to integrate with Perplexity's API.

Import the OpenAI SDK at the top of our `main.js` file:

```js
import { OpenAI } from 'openai';
```

Next, add code to validate the body of the request and initialize the OpenAI SDK with the Perplexity API key:

```js
if (!req.body.prompt) {
  return res.json({
    ok: false,
    error: 'Missing required fields: prompt'
  }, 400);
}

const perplexity = new OpenAI({
  apiKey: process.env.PERPLEXITY_API_KEY,
  baseURL: 'https://api.perplexity.ai',
});
```

This code also allows us to modify what model we use by setting the `PERPLEXITY_MODEL` environment variable.

Send the request to the Perplexity API and return the response:

```js
try {
  const response = await perplexity.chat.completions.create({
    model: 'mistral-7b-instruct',
    max_tokens: parseInt(process.env.PERPLEXITY_MAX_TOKENS ?? '512'),
    messages: [{ role: 'user', content: req.body.prompt }],
    stream: false,
  });
  const completion = response.choices[0].message?.content;
  return res.json({ ok: true, completion }, 200);
} catch (err) {
  return res.json({ ok: false, error: 'Failed to query model.' }, 500);
}
```

This code will send our prompt to the perplexity chat completions API and return the response to the user,
additionally it'll also catch any errors we could encounter and reports them for easy debugging.

With our function now complete, you can deploy it to Appwrite by simply pushing the change to your repository.
{% /section %}

{% section #step-7 step=7 title="Test our function" %}
Now that our function is deployed, we can test it by visiting the function URL in our browser.
Write a prompt and click the submit button, after a brief moment you should see the completion appear below the input.

![Testing the function](/images/docs/ai/integrations/perplexity/demo.avif)
{% /section %}
