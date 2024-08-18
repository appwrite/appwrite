import { Client, Vcs } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('&lt;YOUR_PROJECT_ID&gt;'); // Your project ID

const vcs = new Vcs(client);

const result = await vcs.updateExternalDeployments(
    '<INSTALLATION_ID>', // installationId
    '<REPOSITORY_ID>', // repositoryId
    '<PROVIDER_PULL_REQUEST_ID>' // providerPullRequestId
);

console.log(result);
