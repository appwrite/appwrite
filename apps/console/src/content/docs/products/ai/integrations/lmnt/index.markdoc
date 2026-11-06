---
layout: article
title: Integrating LMNT
description: Learn how to integrate LMNT into your Appwrite project.
difficulty: intermediate
readtime: 15
---

LMNT is a text-to-speech tool that can generate natural-sounding audio from text. It's an excellent tool for dubbing content, creating audiobooks, or even for accessibility.

Integrating LMNT into your Appwrite project is simple. This tutorial will guide you through setting up the LMNT API and incorporating it into your Appwrite project.

# Prerequisites {% #prerequisites %}

- An Appwrite Project
- An Appwrite Bucket
- An [LMNT API Key](https://app.lmnt.com/account)

{% section #step-1 step=1 title="Create new function" %}
Head to the [Appwrite Console](https://cloud.appwrite.io/console), click on **Functions** in the left sidebar and click the **Create Function** button.

{% only_dark %}
![Create function screen](/images/docs/functions/dark/template.avif)
{% /only_dark %}

{% only_light %}
![Create function screen](/images/docs/functions/template.avif)
{% /only_light %}

1. In the Appwrite Console's sidebar, click **Functions**.
2. Click **Create function**.
3. Under **Connect Git repository**, select your provider.
4. After connecting to GitHub, under **Quick start**, select the **Node.js** starter template.
5. In the **Variables** step, add `APPWRITE_BUCKET_ID`, `LMNT_API_KEY`. Generate your LMNT Key [here](https://app.lmnt.com/account). For the `APPWRITE_API_KEY`, tick the box to **Generate API key on completion**.
6. Follow the step-by-step wizard and create the Function.
{% /section %}

{% section #step-2 step=2 title="Add dependencies" %}
Once the Function is created, please navigate to the freshly created repository and clone it to your local machine.

Install the `lmnt-node` package to make requests to the LMNT API and `node-appwrite` package to upload the generated audio files to Appwrite Storage.

```bash
npm install lmnt-node node-appwrite
```
{% /section %}

{% section #step-3 step=3 title="Create utility functions" %}
For this example, the Function can take both `GET` and `POST` requests.

For the `GET` request, return a static HTML page with a form to submit text to the API.
Meanwhile, the `POST` request will send the text to the LMNT API and return the generated audio file.

To begin with, write the code to return the static HTML page. To do this, create a new `src/utils.js` file with the following code:

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
  throwIfMissing(process.env, [
    "LMNT_API_KEY",
    "APPWRITE_API_KEY",
    "APPWRITE_BUCKET_ID",
    "APPWRITE_FUNCTION_PROJECT_ID"
  ]);

  if (req.method === 'GET') {
    return res.text(getStaticFile('index.html'), 200, {
      'Content-Type': 'text/html; charset=utf-8',
    });
  }
};
```

A check is also included to ensure that the `LMNT_API_KEY`, `APPWRITE_API_KEY` and `APPWRITE_BUCKET_ID` environment variables are set.
{% /section %}

{% section #step-5 step=5 title="Create web page" %}
Create an HTML web page that the Function will serve. Create a new file at `static/index.html` with some HTML boilerplate:

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
  <title>LMNT Demo</title>

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
          <h1 class="heading-level-1">LMNT Demo</h1>
          <code class="u-un-break-text"></code>
        </div>
        <p
          class="body-text-1 u-normal u-margin-block-start-8"
          style="max-width: 50rem"
        >
          Use this page to test your implementation with LMNT. Enter
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

All of this together will render a form that will submit your text to the Appwrite Function through a `POST` request. The Appwrite function will then call LMNT's API, upload the audio to Appwrite Storage and return the URL, which will be displayed on your page.
{% /section %}

{% section #step-6 step=6 title="Handle POST Request" %}
Next, you'll add the methods necessary to integrate with the LMNT API.

Import the `Speech` class from `lmnt-node`, and the required features from the Appwrite Node.js SDK at the top of the `main.js` file.

```js
import { Client, Storage, ID, Permission, Role } from "node-appwrite";
import { InputFile } from "node-appwrite/file";
import Speech from 'lmnt-node';
```

Next, add code to validate the body of the request and initialize the Appwrite SDK also within `main.js` following the previously added GET handler:

```js
const endpoint = process.env.APPWRITE_ENDPOINT ?? "https://<REGION>.cloud.appwrite.io/v1";

const client = new Client()
  .setEndpoint(endpoint)
  .setProject(process.env.APPWRITE_FUNCTION_PROJECT_ID)
  .setKey(process.env.APPWRITE_API_KEY);

if (!req.body.text || typeof req.body.text !== "string") {
  return res.json({ ok: false, error: "Missing required field `text`" }, 400);
}
```

Next, send a request to the LMNT API and store the response:

```js
const lmnt = new Speech(process.env.LMNT_API_KEY);
const response = await lmnt.synthesize(req.body.text, 'lily', { format: 'mp3' });
```

This code will send the prompt to the LMNT API and return the audio as a blob. Additionally, any errors will be caught and reported for easy debugging.

{% /section %}

{% section #step-7 step=7 title="Store Audio in Appwrite Storage" %}
Store the audio file in Appwrite Storage for easy retrieval later:

```js
const storage = new Storage(client);

const file = await storage.createFile({
  bucketId: process.env.APPWRITE_BUCKET_ID,
  fileId: ID.unique(),
  file: InputFile.fromBuffer(new Blob([response.audio]), "audio.mp3"),
  permissions: [Permission.read(Role.any())],
});
```

To show it to the user, parse the download URL from Appwrite and return it in the response:

```js
const url = `${endpoint}/storage/buckets/${process.env.APPWRITE_BUCKET_ID}/files/${file.$id}/view?project=${process.env.APPWRITE_FUNCTION_PROJECT_ID}`;

return res.json({ ok: true, response: url });
```

This should finish up the Function. Deploy it to Appwrite by pushing it to the git repository created earlier.
{% /section %}

{% section #step-8 step=8 title="Test the function" %}
Now that the Function is deployed test it by visiting the function URL in your browser.
This should show the UI created earlier. To test it, write a prompt and click the submit button. After a brief moment, you should see the audio below the input.

![Testing the function](/images/docs/ai/integrations/lmnt/demo.avif)
{% /section %}
