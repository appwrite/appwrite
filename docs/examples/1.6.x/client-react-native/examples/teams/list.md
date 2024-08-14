import { Client, Teams } from "react-native-appwrite";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('&lt;YOUR_PROJECT_ID&gt;'); // Your project ID

const teams = new Teams(client);

const result = await teams.list(
    [], // queries (optional)
    '<SEARCH>' // search (optional)
);

console.log(response);
