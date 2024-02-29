import { Client, Functions,  } from "https://deno.land/x/appwrite/mod.ts";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2') // Your project ID
    .setKey('919c2d18fb5d4...a2ae413da83346ad2'); // Your secret API key

const functions = new Functions(client);

const response = await functions.create(
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
