---
layout: article
title: Integrating Pinecone
description: Learn how to integrate Pinecone into your Appwrite project.
difficulty: intermediate
readtime: 15
---

Pinecone is a vector database that allows you to store and query high-dimensional vectors. It is a great tool for building recommendation systems, search engines, and more. In this tutorial, we'll show you how to integrate Pinecone into your Appwrite project.

Inside an Appwrite Function, we'll create a method to that indexes an Appwrite table into Pinecone. We'll also create a method to query the Pinecone index and return the results.

# Prerequisites {% #prerequisites %}

- An Appwrite project
- An Appwrite table
- An [OpenAI API key](https://platform.openai.com/account/api-keys)
- A [Pinecone API key](https://docs.pinecone.io/guides/getting-started/quickstart#2-get-your-api-key)
- A Pinecone index

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
1. In the **Variables** step, add the `PINECONE_API_KEY`, generate it [here](https://docs.pinecone.io/guides/getting-started/quickstart#2-get-your-api-key). Add the `OPENAI_API_KEY`, generate it [here](https://platform.openai.com/account/api-keys).For the `APPWRITE_API_KEY`, tick the box to **Generate API key on completion**.
1. Follow the step-by-step wizard and create the function.
{% /section %}

{% section #step-2 step=2 title="Add dependencies" %}
Once the function is created, navigate to the freshly created repository and clone it to your local machine.

Install the `@pinecone-database/pinecone` package to simplify the process of interacting with the Pinecone API. We'll also install the `openai` package to interact with the OpenAI API.

```bash
npm install @pinecone-database/pinecone openai
```
{% /section %}

{% section #step-3 step=3 title="Create utility function" %}
For this example, the function will be able to take both `GET` and `POST` requests.

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
Write the `GET` request handler in the `src/main.js` file.

```js
import { getStaticFile } from './utils.js';

export default async ({ req, res, error }) => {
  if (req.method === 'GET') {
    const html = getStaticFile('index.html');
    return res.text(html, 200, { 'Content-Type': 'text/html; charset=utf-8' });
  }
};
```

The code checks if all required environment variables are present and then returns the static HTML page when a `GET` request is made.
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
    <title>Pinecone Demo</title>

    <script src="https://unpkg.com/meilisearch@0.34.1"></script>
    <script src="https://unpkg.com/alpinejs" defer></script>

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
            <h1 class="heading-level-1">Pinecone Demo</h1>
            <code class="u-un-break-text"></code>
            </div>
            <p
            class="body-text-1 u-normal u-margin-block-start-8"
            style="max-width: 50rem"
            >
            Use this demo to verify that the sync between Appwrite Databases and
            Pinecone was successful. Search your Pinecone vector database using
            the input below.
            </p>
        </div>
        </div>
        <div
            class="container u-margin-block-start-negative-56"
            x-data="{ search: '', results: [ ] }"
            x-init="$watch('search', async (value) => { results = await onSearch(value) })"
        >
        <div class="card u-flex u-gap-24 u-flex-vertical">
            <div id="searchbox">
            <div
                class="input-text-wrapper is-with-end-button u-width-full-line"
            >
                <input x-model="search" type="search" placeholder="Search" />
                <div class="icon-search" aria-hidden="true"></div>
            </div>
            </div>
            <div id="hits" class="u-flex u-flex-vertical u-gap-12">
            <template x-for="result in results">
                <div class="card">
                <pre x-text="JSON.stringify(result, null, '\t')"></pre>
                </div>
            </template>
            </div>
        </div>
        </div>
    </main>
    <script>
        window.onSearch = async function (prompt) {
            const response = await fetch('/search', {
                method: 'POST',
                body: JSON.stringify({ prompt }),
                headers: {
                'Content-Type': 'application/json',
                },
            });
            return response.matches;
        };
    </script>
</body>
```

This will render a form that will submit your search query to the function and display the results.
{% /section %}

{% section #step-6 step=6 title="Setup SDKs" %}
Add methods necessary to integrate with the OpenAI and Pinecone APIs

Import `openai` and `@pinecone-database/pinecone` at the top of the `main.js` file:

```js
import { Pinecone } from '@pinecone-database/pinecone';
import { OpenAI } from 'openai';
```

Add the following code at the end of request handler in the `main.js` file:

```js
const openai = new OpenAI();

const pinecone = new Pinecone();
const pineconeIndex = pinecone.index(process.env.PINECONE_INDEX_ID);
```

The functions checks the request method, and then initializes the OpenAI and Pinecone SDKs.
{% /section %}

{% section #step-7 step=7 title="Handle search requests" %}
To handle the search requests, add the following code to the end of the request handler in the `main.js` file:

```js
if (req.path === '/search') {
    const queryEmbedding = await openai.embeddings.create({
        model: 'text-embedding-ada-002',
        input: req.body.prompt,
    });

    const searchResults = await pineconeIndex.query({
        vector: queryEmbedding.data[0].embedding,
        topK: 5,
    });

    return res.json(searchResults);
}
```

For all requests with the path `/search`, the function sends the search query to the OpenAI API to get the embedding. The function then queries the Pinecone index with the embedding and returns the results.
{% /section %}

{% section #step-8 step=8 title="Handle indexing requests" %}
The Appwrite table needs to be indexed into the Pinecone index. Create a new file at `src/appwrite.js` with the following code:

```js
import { Client, TablesDB, Query } from 'node-appwrite';

export default class AppwriteService {
  constructor() {
    const client = new Client();
    client
      .setEndpoint(
        process.env.APPWRITE_ENDPOINT ?? 'https://<REGION>.cloud.appwrite.io/v1'
      )
      .setProject(process.env.APPWRITE_FUNCTION_PROJECT_ID)
      .setKey(process.env.APPWRITE_API_KEY);

    this.tablesDB = new TablesDB(client);
  }

  async getAllRows(databaseId, tableId) {
    const cumulative = [];

    let cursor = null;
    do {
      const queries = [Query.limit(100)];

      if (cursor) {
        queries.push(Query.cursorAfter(cursor));
      }

      const { rows } = await this.tablesDB.listRows({
        databaseId,
        tableId,
        queries
      });

      if (rows.length === 0) {
        break;
      }

      cursor = rows[rows.length - 1].$id;

      cumulative.push(...rows);
    } while (cursor);

    return cumulative;
  }
}
```

The service provides a method to iterate the rows contained within an entire table, fetching the limit of 100 rows per request.

```js
const appwrite = new AppwriteService();

const rows = await appwrite.getAllRows(
  process.env.APPWRITE_DATABASE_ID,
  process.env.APPWRITE_TABLE_ID
);

const embeddings = await Promise.all(
  rows.map(async (row) => {
    const record = await openai.embeddings.create({
      model: 'text-embedding-ada-002',
      input: JSON.stringify(row),
    });
    return {
      id: row.$id,
      values: record.data[0].embedding,
      metadata: row,
    };
  })
);

await pineconeIndex.upsert(embeddings);
```

The code fetches all rows from the Appwrite table, then sends each row to the OpenAI API to get the embedding. The embeddings are then uploaded to the Pinecone index.
{% /section %}

{% section #step-9 step=9 title="Test the function" %}
Now that the function is deployed, test it by visiting the function URL in your browser.
This should show the UI created earlier and to test it, write a search query and click the submit button. After a brief moment you should see the matched results.
{% /section %}
