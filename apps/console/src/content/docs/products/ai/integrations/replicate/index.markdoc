---
layout: article
title: Integrating Replicate
description: Learn how to integrate Replicate into your Appwrite project.
difficulty: intermediate
readtime: 15
---

Integrating Replicate into your Appwrite project is simple. This tutorial will guide you through the process of setting up the Replicate API and integrating it into your Appwrite project.

# Prerequisites {% #prerequisites %}

- An Appwrite Project
- A [Replicate API Key](https://replicate.com/docs/reference/http#authentication)

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
1. In the **Variables** step, add the `REPLICATE_API_KEY`, generate it [here](https://replicate.com/docs/reference/http#authentication).
1. Follow the step-by-step wizard and create the function.
{% /section %}

{% section #step-2 step=2 title="Add Replicate SDK" %}
Once the function is created, clone the function and open it in your development environment.

Once you have the repository open, you can install the Replicate by running the following command in your terminal:

```bash
npm install replicate
```
{% /section %}

{% section #step-3 step=3 title="Create utility function" %}
For our example, our function will be able to take both `GET` and `POST` requests.

For the `GET` request, return a static HTML page which we'll write later that will use AlpineJS to make a `POST` request to our function.
Meanwhile, our `POST` request will use the Replicate SDK to make a request to the Replicate API.

To begin with we will write the code to return the static HTML page, to do this we'll create a new `src/utils.js` file with the following code:

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
  <title>Replicate Demo</title>

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

      return json;
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
        <div class="u-flex u-gap-16 u-flex-justify-center u-margin-block-start-16">
          <h1 class="heading-level-1">Replicate Demo</h1>
          <code class="u-un-break-text"></code>
        </div>
        <p class="body-text-1 u-normal u-margin-block-start-8" style="max-width: 50rem">
          Use this page to test your implementation with Replicate. Enter
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
              <option value="audio">Audio</option>
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
                <img class="u-max-width-400" x-bind:src="answer.response" alt="Replicate output" />
              </template>
              <template x-if="answer.type === 'audio'" class="u-flex u-gap-12">
                <audio x-bind:src="answer.response" controls></audio>
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

All of this together will render a form that will submit your question to the Appwrite Function through a POST request which we'll create next. The Appwrite Function will call Replicate's API and return the response, which will be displayed on your page using different conditional statements depending on the output media type.
{% /section %}

{% section #step-6 step=6 title="Handle POST Request" %}
Now that we're serving a basic HTML page, we can add methods necessary to integrate with Replicate's API.

Import the Replicate SDK at the top of our `main.js` file:

```js
import Replicate from "replicate";
```

Next after we serve the HTML we're going to add code to validate the body of the request, define our models and initialize the Replicate SDK:

```js
const models = {
  'audio': 'meta/musicgen:b05b1dff1d8c6dc63d14b0cdb42135378dcb87f6373b0d3d341ede46e59e2b38',
  'text': 'meta/llama-2-70b-chat',
  'image': 'stability-ai/sdxl:39ed52f2a78e934b3ba6e2a89f5b1c712de7dfea535525255b1aa35c5565e08b'
};

if (req.body.type !== 'audio' && req.body.type !== 'text' && req.body.type !== 'image') {
  return res.json({ ok: false, error: 'Invalid type' }, 400);
}

const replicate = new Replicate();
```

In this example we're going to be using meta's musicgen and llama2 70b models for music and text generation while using Stability AI's SDXL model for image generation. You can find more models on the [Replicate explore page](https://replicate.com/explore).

Next we're going to add some per model configurations:

```js
let request = {
  input: {
    prompt: req.body.prompt,
  }
};

// Allows you to tinker parameters for individual output types
switch (req.body.type) {
  case 'audio':
    request.input = {
      ...request.input,
      length: 30,
    }
  break;
  case 'text':
    request.input = {
      ...request.input,
      max_new_tokens: 512,
    }
  break;
  case 'image':
    request.input = {
      ...request.input,
      width: 512,
      height: 512,
      negative_prompt: "deformed, noisy, blurry, distorted",
    }
  break;
};
```

This allows us to individually configure each of the models we're using, feel free to play with this configuration to get the best results for your use case.

Finally with our request built we can call the replicate API and generate a prediction:

```js
let response;

try {
  response = await replicate.run(models[req.body.type], request);
} catch (err) {
  return res.json({ ok: false, error: 'Failed to run model' }, 500);
}

if (req.body.type === 'image') {
  response = response[0]
} else if (req.body.type === 'text') {
  response = response.join('');
}

return res.json({ ok: true, response, type: req.body.type }, 200);
```

This code will send our prompt to the replicate API and return the response to the user,
additionally it'll also catch any errors we could encounter and reports them for easy debugging.

With our function now complete, you can deploy it to Appwrite by simply pushing the change to your repository.
{% /section %}

{% section #step-7 step=7 title="Test our function" %}
Now that our function is deployed, we can test it by visiting the function URL in our browser.
This should show the UI we created earlier and to test it we can write a prompt and click the submit button, after a brief moment you should see the completion appear below the input.

![Testing the function](/images/docs/ai/integrations/replicate/demo.avif)
{% /section %}
