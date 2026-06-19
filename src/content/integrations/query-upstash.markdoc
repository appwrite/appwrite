---
layout: integration
title: Query Upstash
description: Store data in your Upstash vector database and query it for similar data
date: 2024-07-30
featured: false
isPartner: true
isNew: false
cover: /images/integrations/query-upstash/cover.avif
category: databases
product: 
    avatar: '/images/integrations/avatars/upstash.avif'
    vendor: Upstash
    description: 'Upstash Vector provides a powerful vector database designed to handle complex queries and search functionalities.'
platform: 
    - 'Self-hosted'
    - 'Cloud'
images: 
    - /images/integrations/query-upstash/cover.avif
    - /images/integrations/query-upstash/index.avif
    - /images/integrations/query-upstash/template.avif
    - /images/integrations/query-upstash/variables.avif
---

Upstash Vector provides a powerful vector database designed to handle complex queries and search functionalities. It’s ideal for applications that require fast and efficient handling of vector data.

# How does the integration work?

You can utilize a pre-built Appwrite function template to integrate an Upstash Vector database with your app. This setup enables you to store and query vector data efficiently, enhancing your application's search and recommendation features.

# How to implement

To implement the Upstash Vector integration, follow these simple steps:

### Step 1: Set up the Upstash Vector database

First, [create an account on Upstash](https://console.upstash.com/login) and set up a **new Vector index** with your preferred region and embedding model. Once it is ready, copy your **endpoint** and **token** from the index’s dashboard for further usage

![Upstash vector index](/images/integrations/query-upstash/index.avif)

## Step 2: Create the Appwrite Function

For this step, you must [create an account on Appwrite Cloud](https://cloud.appwrite.io/register) or [self-host Appwrite](https://appwrite.io/docs/advanced/self-hosting) if you haven’t already. If you decide to self-host Appwrite, there are [additional setup steps](https://appwrite.io/docs/advanced/self-hosting/functions) to use Appwrite Function templates.

Head over to the Appwrite console, navigate to the **Functions** page, click the **Templates** tab, and search for the **Upstash** function template.

![Function template](/images/integrations/query-upstash/template.avif)

Add the **Upstash endpoint** and **token** you saved in the **Variables** step. Then, create a new repository with the default branch and root directory settings. You can edit this repository later to update the function logic.

![Env variables](/images/integrations/query-upstash/variables.avif)

## Step 3: Test the function

Once the function is ready, visit the **Domains** tab on the **Functions** page and copy the domain URL to test the function. Sending a GET (HTTP) Request to this endpoint will add a randomly generated mock embedding and query for the one most similar entry in the database.

```bash
curl DEPLOYED_FUNCTION_DOMAIN \
  -H 'accept: application/json'
```

You can add other database functionalities by editing the function generated on GitHub.

# Read more about Upstash and Appwrite Functions

If you would like to learn more about Upstash and Appwrite Functions, we have some resources that you should visit:

- [Integrate SQL, NoSQL, Vector, Graph, or any database into your Appwrite project](https://appwrite.io/blog/post/integrate-sql-nosql-vector-graph-or-any-database-into-your-appwrite-project)
- [Appwrite Functions docs](https://appwrite.io/docs/products/functions)