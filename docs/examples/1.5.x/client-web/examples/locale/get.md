import { Client, Locale } from "appwrite";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2'); // Your project ID

const locale = new Locale(client);

const result = await locale.get();

console.log(response);
