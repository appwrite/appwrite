import { Client, Graphql } from "appwrite";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2'); // Your project ID

const graphql = new Graphql(client);

const result = await graphql.mutation(
    {} // query
);

console.log(response);
