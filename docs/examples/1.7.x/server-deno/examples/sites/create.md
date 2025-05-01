import { Client, Sites, , ,  } from "https://deno.land/x/appwrite/mod.ts";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your secret API key

const sites = new Sites(client);

const response = await sites.create(
    '<SITE_ID>', // siteId
    '<NAME>', // name
    .Analog, // framework
    .Node145, // buildRuntime
    false, // enabled (optional)
    false, // logging (optional)
    1, // timeout (optional)
    '<INSTALL_COMMAND>', // installCommand (optional)
    '<BUILD_COMMAND>', // buildCommand (optional)
    '<OUTPUT_DIRECTORY>', // outputDirectory (optional)
    .Static, // adapter (optional)
    '<INSTALLATION_ID>', // installationId (optional)
    '<FALLBACK_FILE>', // fallbackFile (optional)
    '<PROVIDER_REPOSITORY_ID>', // providerRepositoryId (optional)
    '<PROVIDER_BRANCH>', // providerBranch (optional)
    false, // providerSilentMode (optional)
    '<PROVIDER_ROOT_DIRECTORY>', // providerRootDirectory (optional)
    '' // specification (optional)
);
