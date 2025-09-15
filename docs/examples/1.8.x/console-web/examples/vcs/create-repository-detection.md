import { Client, Vcs, VCSDetectionType } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const vcs = new Vcs(client);

const result = await vcs.createRepositoryDetection({
    installationId: '<INSTALLATION_ID>',
    providerRepositoryId: '<PROVIDER_REPOSITORY_ID>',
    type: VCSDetectionType.Runtime,
    providerRootDirectory: '<PROVIDER_ROOT_DIRECTORY>' // optional
});

console.log(result);
