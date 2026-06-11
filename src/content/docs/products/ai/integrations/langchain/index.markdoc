---
layout: article
title: Integrating LangChain
description: Learn how to integrate LangChain into your Appwrite project.
difficulty: intermediate
readtime: 15
---

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

Add the following dependencies to the `package.json` file:

```bash
npm install @pinecone-database/pinecone openai @langchain/core @langchain/openai @langchain/pinecone langchain
```
{% /section %}

{% section #step-3 step=3 title="Create utility functions" %}
For this example, the function will be able to take both `GET` and `POST` requests.

For the `GET` request, return a static HTML page that will have a form to search the Pinecone index. Meanwhile the `POST /search` requests will send the search query to the Pinecone API and return the results.
All other `POST` requests will trigger the indexing of the Appwrite table into the Pinecone index.

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
    const html = getStaticFile('index.html');
    return res.text(html, 200, { 'Content-Type': 'text/html; charset=utf-8' });
  }
};
```

The function will throw an error if any of the required environment variables are missing. The function will return the static HTML page when a `GET` request is made.
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

{% section #step-7 step=7 title="Handle prompt requests" %}
First add the following imports from LangChain:

```js
import { formatDocumentsAsString } from 'langchain/util/document';
import { ChatOpenAI } from '@langchain/openai';
import { PineconeStore } from '@langchain/pinecone';
import { PromptTemplate } from '@langchain/core/prompts';
import {
  RunnableSequence,
  RunnablePassthrough,
} from '@langchain/core/runnables';
import { StringOutputParser } from '@langchain/core/output_parsers';
```

To handle the prompt requests, add the following code to the end of the request handler in the `main.js` file:

```js
if (req.path === '/prompt') {
  if (!req.body.prompt || typeof req.body.prompt !== 'string') {
    return res.json(
      { ok: false, error: 'Missing required field `prompt`' },
      400
    );
  }

  const vectorStore = await PineconeStore.fromExistingIndex(
    new OpenAIEmbeddings(),
    { pineconeIndex }
  );

  const prompt = PromptTemplate.fromTemplate(
    `Answer the question based with following context:{context}\nQuestion: {question}`
  );

  const chain = RunnableSequence.from([
    {
      context: vectorStore.asRetriever().pipe(formatDocumentsAsString),
      question: new RunnablePassthrough(),
    },
    prompt,
    new ChatOpenAI(),
    new StringOutputParser(),
  ]);

  const result = await chain.invoke(req.body.prompt);

  return res.json({ ok: true, completion: result }, 200);
}
```

This code will handle the prompt requests by creating a LangChain sequence that will format the rows as strings, prompt the user for a question, and then use the OpenAI API to generate a response. The response is then parsed and returned to the user.
{% /section %}

{% section #step-8 step=8 title="Handle index requests" %}
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

const appwriteRows = await appwrite.getAllRows(
  process.env.APPWRITE_DATABASE_ID,
  process.env.APPWRITE_TABLE_ID
);

const rows = appwriteRows.map(
  (row) =>
    new Row({
      metadata: { id: row.$id },
      pageContent: Object.entries(row)
        .filter(([key, _]) => !key.startsWith('$'))
        .map(([key, value]) => `${key}: ${value}`)
        .join('\n'),
    })
);

await PineconeStore.fromDocuments(rows, new OpenAIEmbeddings(), {
  pineconeIndex,
  maxConcurrency: 5,
});

```

Within our function handler, the service is instantiated and used to create an array of LangChain documents. LangChain documents can then be used with the `PineconeStore.fromDocuments` method to retrieve embeddings from OpenAI and upsert them to your Pinecone index.
{% /section %}

{% section #step-9 step=9 title="Test the function" %}
Now that the function is deployed, test it by visiting the function URL in your browser.
This should show the UI created earlier and to test it, write a prompt and click the submit button. After a brief moment you should see the matched results.
{% /section %}
