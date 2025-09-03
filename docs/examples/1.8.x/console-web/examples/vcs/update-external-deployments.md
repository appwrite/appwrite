import { Client, Vcs } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const vcs = new Vcs(client);

const result = await vcs.updateExternalDeployments({
    installationId: '<INSTALLATION_ID>',
    repositoryId: '<REPOSITORY_ID>',
    providerPullRequestId: '<PROVIDER_PULL_REQUEST_ID>'
});

console.log(result);
