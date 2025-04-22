import { Client, Vcs } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const vcs = new Vcs(client);

const result = await vcs.createRepository(
    '<INSTALLATION_ID>', // installationId
    '<NAME>', // name
    false // private
);

console.log(result);
