import { Client, Graphql } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const graphql = new Graphql(client);

const result = await graphql.mutation(
    {} // query
);

console.log(result);
