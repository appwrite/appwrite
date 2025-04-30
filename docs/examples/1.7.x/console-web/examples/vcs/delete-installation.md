import { Client, Vcs } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://example.com/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const vcs = new Vcs(client);

const result = await vcs.deleteInstallation(
    '<INSTALLATION_ID>' // installationId
);

console.log(result);
