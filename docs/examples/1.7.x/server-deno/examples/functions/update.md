import { Client, Functions,  } from "https://deno.land/x/appwrite/mod.ts";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your secret API key

const functions = new Functions(client);

const response = await functions.update(
    '<FUNCTION_ID>', // functionId
    '<NAME>', // name
    .Node145, // runtime (optional)
    ["any"], // execute (optional)
    [], // events (optional)
    '', // schedule (optional)
    1, // timeout (optional)
    false, // enabled (optional)
    false, // logging (optional)
    '<ENTRYPOINT>', // entrypoint (optional)
    '<COMMANDS>', // commands (optional)
    [], // scopes (optional)
    '<INSTALLATION_ID>', // installationId (optional)
    '<PROVIDER_REPOSITORY_ID>', // providerRepositoryId (optional)
    '<PROVIDER_BRANCH>', // providerBranch (optional)
    false, // providerSilentMode (optional)
    '<PROVIDER_ROOT_DIRECTORY>', // providerRootDirectory (optional)
    '' // specification (optional)
);
