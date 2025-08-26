import { Client, Vcs } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const vcs = new Vcs(client);

const result = await vcs.getRepositoryContents({
    installationId: '<INSTALLATION_ID>',
    providerRepositoryId: '<PROVIDER_REPOSITORY_ID>',
    providerRootDirectory: '<PROVIDER_ROOT_DIRECTORY>', // optional
    providerReference: '<PROVIDER_REFERENCE>' // optional
});

console.log(result);
