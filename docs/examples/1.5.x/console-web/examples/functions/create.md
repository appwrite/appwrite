import { Client, Functions,  } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2'); // Your project ID

const functions = new Functions(client);

const result = await functions.create(
    '<FUNCTION_ID>', // functionId
    '<NAME>', // name
    .Node145, // runtime
    ["any"], // execute (optional)
    [], // events (optional)
    '', // schedule (optional)
    1, // timeout (optional)
    false, // enabled (optional)
    false, // logging (optional)
    '<ENTRYPOINT>', // entrypoint (optional)
    '<COMMANDS>', // commands (optional)
    '<INSTALLATION_ID>', // installationId (optional)
    '<PROVIDER_REPOSITORY_ID>', // providerRepositoryId (optional)
    '<PROVIDER_BRANCH>', // providerBranch (optional)
    false, // providerSilentMode (optional)
    '<PROVIDER_ROOT_DIRECTORY>', // providerRootDirectory (optional)
    '<TEMPLATE_REPOSITORY>', // templateRepository (optional)
    '<TEMPLATE_OWNER>', // templateOwner (optional)
    '<TEMPLATE_ROOT_DIRECTORY>', // templateRootDirectory (optional)
    '<TEMPLATE_BRANCH>' // templateBranch (optional)
);

console.log(response);
