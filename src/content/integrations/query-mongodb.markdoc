---
layout: integration
title: Query MongoDB Atlas
description: Save a document in your MongoDB cluster and list all stored documents
date: 2024-07-30
featured: false
isPartner: true
isNew: false
cover: /images/integrations/query-mongodb/cover.avif
category: databases
product: 
    avatar: '/images/integrations/avatars/mongodb.avif'
    vendor: MongoDB
    description: 'MongoDB is a popular NoSQL database known for its flexibility and scalability.'
platform: 
    - 'Self-hosted'
    - 'Cloud'
images: 
    - /images/integrations/query-mongodb/cover.avif
    - /images/integrations/query-mongodb/first-cluster.avif
    - /images/integrations/query-mongodb/connection-string.avif
    - /images/integrations/query-mongodb/network-access.avif
    - /images/integrations/query-mongodb/template.avif
    - /images/integrations/query-mongodb/variables.avif
---

MongoDB is a popular NoSQL database known for its flexibility and scalability. It allows developers to store and manage large amounts of data with ease.

# How does the integration work?

You can utilize a pre-built Appwrite function template to integrate a MongoDB Atlas cluster with your app. This will allow you to leverage MongoDB's powerful data management capabilities and NoSQL database and potentially extend it with Appwrite’s ecosystem.

# How to implement

To implement the MongoDB Atlas integration, follow these simple steps:

## Step 1: Set up a MongoDB database

First, [create an account on MongoDB Atlas](https://www.mongodb.com/cloud/atlas/register) and deploy your first cluster.

![MongoDB Atlas first cluster](/images/integrations/query-mongodb/first-cluster.avif)

Create your username and password and copy the **connection string** for your MongoDB Atlas cluster. This connection string will be used to connect the Appwrite Function to your MongoDB cluster.

![MongoDB Atlas connection string](/images/integrations/query-mongodb/connection-string.avif)

Next, head to the **Network Access** page from the left sidebar, click on the **Add IP Address** button, and allow access from anywhere.

![MongoDB Atlas network access](/images/integrations/query-mongodb/network-access.avif)

## Step 2: Create the Appwrite Function

For this step, you must [create an account on Appwrite Cloud](https://cloud.appwrite.io/register) or [self-host Appwrite](https://appwrite.io/docs/advanced/self-hosting) if you haven’t already. In case you decide to self-host Appwrite, there are [additional setup steps](https://appwrite.io/docs/advanced/self-hosting/functions) to use Appwrite Function templates.

Head over to the Appwrite console, navigate to the **Functions** page, click on the **Templates** tab, and search for the **MongoDB** function template.

![Function template](/images/integrations/query-mongodb/template.avif)

Add the **MongoDB connection string** you saved in the **Variables** step. Then, create a new repository with the default branch and root directory settings. You can edit this repository later to update the function logic.

![Env variables](/images/integrations/query-mongodb/variables.avif)

## Step 3: Test the function

Once the function is ready, visit the **Domains** tab on the **Functions** page and copy the domain URL to test the function. Sending a GET (HTTP) Request to this endpoint will add a randomly generated mock document and list all the documents in the database.

```bash
curl DEPLOYED_FUNCTION_DOMAIN \
  -H 'accept: application/json'
```

You can add other database functionalities by editing the function generated for you on GitHub.

# Read more about MongoDB Atlas and Appwrite Functions

If you would like to learn more about MongoDB Atlas and Appwrite Functions, we have some resources that you should visit:

- [Integrate SQL, NoSQL, Vector, Graph, or any database into your Appwrite project](https://appwrite.io/blog/post/integrate-sql-nosql-vector-graph-or-any-database-into-your-appwrite-project)
- [Understanding data queries in database management](https://appwrite.io/blog/post/understand-data-queries)
- [Appwrite Functions docs](https://appwrite.io/docs/products/functions)