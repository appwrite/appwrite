import { Client, Vcs, VCSDetectionType } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const vcs = new Vcs(client);

const result = await vcs.listRepositories({
    installationId: '<INSTALLATION_ID>',
    type: VCSDetectionType.Runtime,
    search: '<SEARCH>', // optional
    queries: [] // optional
});

console.log(result);
