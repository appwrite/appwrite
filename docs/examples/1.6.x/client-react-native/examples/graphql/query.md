import { Client, Graphql } from "react-native-appwrite";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('&lt;YOUR_PROJECT_ID&gt;'); // Your project ID

const graphql = new Graphql(client);

const result = await graphql.query(
    {} // query
);

console.log(result);
