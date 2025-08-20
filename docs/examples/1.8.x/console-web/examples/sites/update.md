import { Client, Sites, , ,  } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const sites = new Sites(client);

const result = await sites.update({
    siteId: '<SITE_ID>',
    name: '<NAME>',
    framework: .Analog,
    enabled: false,
    logging: false,
    timeout: 1,
    installCommand: '<INSTALL_COMMAND>',
    buildCommand: '<BUILD_COMMAND>',
    outputDirectory: '<OUTPUT_DIRECTORY>',
    buildRuntime: .Node145,
    adapter: .Static,
    fallbackFile: '<FALLBACK_FILE>',
    installationId: '<INSTALLATION_ID>',
    providerRepositoryId: '<PROVIDER_REPOSITORY_ID>',
    providerBranch: '<PROVIDER_BRANCH>',
    providerSilentMode: false,
    providerRootDirectory: '<PROVIDER_ROOT_DIRECTORY>',
    specification: ''
});

console.log(result);
