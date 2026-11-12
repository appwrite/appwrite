---
layout: article
title: Integrating ElevenLabs
description: Learn how to integrate ElevenLabs into your Appwrite project.
difficulty: intermediate
readtime: 15
---

ElevenLabs is an text to speech tool that can generate natural sounding audio from text. It's an excellent tool for dubbing content, creating audiobooks, or even for accessibility purposes.

Integrating ElevenLabs into your Appwrite project is simple. This tutorial will guide you through the process of setting up the ElevenLabs API and integrating it into your Appwrite project.

# Prerequisites {% #prerequisites %}

- An Appwrite Project
- An [ElevenLabs API Key](https://elevenlabs.io/)

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
1. In the **Variables** step, add the `ELEVENLABS_API_KEY`, generate it [here](https://elevenlabs.io/). For the `APPWRITE_API_KEY`, tick the box to **Generate API key on completion**.
1. Follow the step-by-step wizard and create the function.
{% /section %}

{% section #step-2 step=2 title="Add dependencies" %}
Once the function is created, navigate to the freshly created repository and clone it to your local machine.

Install the `undici` package to make requests to the ElevenLabs API and `node-appwrite` package to upload the generated audio files to Appwrite Storage.

```bash
npm install undici node-appwrite
```
{% /section %}

{% section #step-3 step=3 title="Create utility functions" %}
For this example, the function will be able to take both `GET` and `POST` requests.

For the `GET` request, return a static HTML page that will have a form to submit text to the API.
Meanwhile the `POST` request will send the text to the ElevenLabs API and return the generated audio file.

To begin with write the code to return the static HTML page, to do this create a new `src/utils.js` file with the following code:

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

A check is also included to ensure that the `ELEVENLABS_API_KEY`, `APPWRITE_API_KEY` and `APPWRITE_BUCKET_ID` environment variables is set.
{% /section %}

{% section #step-5 step=5 title="Create web page" %}
Create a HTML web page that the function will serve. Create a new file at `static/index.html` with some HTML boilerplate:

```html
<!doctype html>
<html lang="en">
</html>
```

Within the `<html>` tag, Add a `<head>` tag that will define the style and scripts.

```html
<head>
  <meta charset="UTF-8" />
  <meta http-equiv="X-UA-Compatible" content="IE=edge" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>ElevenLabs Demo</title>

  <script>
    async function onSubmit(prompt) {
      const response = await fetch('/', {
        method: 'POST',
        body: JSON.stringify({ text: prompt }),
        headers: {
          'Content-Type': 'application/json',
        },
      });

      const json = await response.json();

      if (!json.ok || json.error) {
        alert(json.error);
      }

      return json.response;
    }
  </script>

  <script src="//unpkg.com/alpinejs" defer></script>

  <link rel="stylesheet" href="https://unpkg.com/@appwrite.io/pink" />
  <link rel="stylesheet" href="https://unpkg.com/@appwrite.io/pink-icons" />
</head>
```

And after the `</head>` tag add this `<body>` which will contain the actual form:

```html
<body>
  <main class="main-content">
    <div class="top-cover u-padding-block-end-56">
      <div class="container">
        <div
          class="u-flex u-gap-16 u-flex-justify-center u-margin-block-start-16"
        >
          <h1 class="heading-level-1">ElevenLabs Demo</h1>
          <code class="u-un-break-text"></code>
        </div>
        <p
          class="body-text-1 u-normal u-margin-block-start-8"
          style="max-width: 50rem"
        >
          Use this page to test your implementation with ElevenLabs. Enter
          text and receive an audio response.
        </p>
      </div>
    </div>
    <div
      class="container u-margin-block-start-negative-56"
      x-data="{ prompt: '', response: '', loading: false }"
    >
      <div class="card u-flex u-gap-24 u-flex-vertical">
        <div class="u-flex u-cross-center u-gap-8">
          <div
            class="input-text-wrapper is-with-end-button u-width-full-line"
          >
            <input x-model="prompt" type="search" placeholder="Enter text" />
            <div class="icon-search" aria-hidden="true"></div>
          </div>

          <button
            class="button"
            x-bind:disabled="loading"
            x-on:click="async () => { loading = true; response = ''; try { response = await onSubmit(prompt) } catch(err) { console.error(err); } finally { loading = false; } }"
          >
            <span class="text">Generate</span>
          </button>
        </div>
        <template x-if="response">
          <div class="u-flex u-flex-vertical u-gap-12">
            <div class="u-flex u-flex-vertical u-gap-12 card">
              <div class="u-flex u-gap-12">
                <h5 class="eyebrow-heading-2">Output:</h5>
              </div>

              <div>
                <audio x-bind:src="response" controls></audio>
              </div>
            </div>
          </div>
        </template>
      </div>
    </div>
  </main>
</body>
```

All of this together will render a form that will submit your text to the Appwrite function through a POST request which you'll create next. The Appwrite function will call ElevenLabs's API, upload the audio to Appwrite Storage and return the URL, which will be displayed on your page.
{% /section %}

{% section #step-6 step=6 title="Handle POST Request" %}
Add methods necessary to integrate with the ElevenLabs API:

Import `fetch`, and the required features from the Appwrite Node.js SDK at the top of the `main.js` file

```js
import { Client, Storage, ID, Permission, Role } from "node-appwrite";
import { InputFile } from "node-appwrite/file";
import { fetch } from "undici";
```

Next add code to validate the body of the request and initialize the Appwrite SDK:

```js
const client = new Client()
  .setEndpoint(process.env.APPWRITE_ENDPOINT ?? "https://<REGION>.cloud.appwrite.io/v1")
  .setProject(process.env.APPWRITE_FUNCTION_PROJECT_ID)
  .setKey(process.env.APPWRITE_API_KEY);

if (!req.body.text || typeof req.body.text !== "string") {
  return res.json({ ok: false, error: "Missing required field `text`" }, 400);
}
```

Send a request to the ElevenLabs API and return the response:

```js
const body = {
  accent: req.body.accent || "british",
  accent_strength: 1.0,
  age: req.body.age || "young",
  gender: req.body.gender || "female",
  text: req.body.text,
};

const response = await fetch(
  "https://api.elevenlabs.io/v1/voice-generation/generate-voice",
  {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      "xi-api-key": process.env.ELEVENLABS_API_KEY,
    },
    body: JSON.stringify(body),
  },
);

if (response.status !== 200) {
  return res.json({ ok: false, error: "Failed to generate audio" }, 500);
}
```

This code will send the prompt to the ElevenLabs API and return the audio as a blob,
additionally it'll also catch any errors we could encounter and reports them for easy debugging.
{% /section %}

{% section #step-7 step=7 title="Store Audio in Appwrite Storage" %}
Store the audio file in Appwrite Storage for easy retrieval later:

```js
const storage = new Storage(client);

const file = await storage.createFile({
  bucketId: process.env.APPWRITE_BUCKET_ID,
  fileId: ID.unique(),
  file: InputFile.fromBuffer(await response.blob(), "audio.mp3"),
  permissions: [Permission.read(Role.any())],
});
```

To show it to the user, parse the download URL from Appwrite and return it in the response:

```js
const url = `${process.env.APPWRITE_ENDPOINT}/storage/buckets/${process.env.APPWRITE_BUCKET_ID}/files/${file.$id}/view?project=${process.env.APPWRITE_FUNCTION_PROJECT_ID}`;

return res.json({ ok: true, response: url });
```

This should finish up the function, Deploy it to Appwrite by pushing to the git repository created earlier.
{% /section %}

{% section #step-8 step=8 title="Test the function" %}
Now that the function is deployed, test it by visiting the function URL in your browser.
This should show the UI created earlier and to test it, write a prompt and click the submit button. After a brief moment you should see the audio appear below the input.

![Testing the function](/images/docs/ai/integrations/elevenlabs/demo.avif)
{% /section %}
