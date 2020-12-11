import * as sdk from "https://deno.land/x/appwrite/mod.ts";

let client = new sdk.Client();
let users = new sdk.Users(client);

client
    .setEndpoint('https://[HOSTNAME_OR_IP]/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2') // Your project ID
    .setKey('919c2d18fb5d4...a2ae413da83346ad2') // Your secret API key
;

let promise = users.create('email@example.com', 'password');


const sdk = require('node-appwrite');

let client = new sdk.Client();

client
    .setEndpoint(process.env.APPWRITE_ENDPOINT) // Your API Endpoint
    .setProject(process.env.APPWRITE_PROJECT) // Your project ID
    .setKey(process.env.APPWRITE_SECRET) // Your secret API key
;

let storage = new sdk.Storage(client);

// let result = storage.getFile(process.env.APPWRITE_FILEID);

console.log(process.env.APPWRITE_FUNCTION_ID);
console.log(process.env.APPWRITE_FUNCTION_NAME);
console.log(process.env.APPWRITE_FUNCTION_TAG);
console.log(process.env.APPWRITE_FUNCTION_TRIGGER);
console.log(process.env.APPWRITE_FUNCTION_ENV_NAME);
console.log(process.env.APPWRITE_FUNCTION_ENV_VERSION);
// console.log(result['$id']);
console.log(process.env.APPWRITE_FUNCTION_EVENT);
console.log(process.env.APPWRITE_FUNCTION_EVENT_PAYLOAD);