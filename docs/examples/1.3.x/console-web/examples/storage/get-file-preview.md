import { Client, Storage } from "@appwrite.io/console";

const client = new Client();

const storage = new Storage(client);

client
    .setEndpoint('https://[HOSTNAME_OR_IP]/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2') // Your project ID
;

const result = storage.getFilePreview('[BUCKET_ID]', '[FILE_ID]');

console.log(result); // Resource URL