import { Client, Vcs } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const vcs = new Vcs(client);

const result = await vcs.listInstallations(
    [], // queries (optional)
    '<SEARCH>' // search (optional)
);

console.log(result);
