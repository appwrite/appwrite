import { Client, Projects } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const projects = new Projects(client);

const result = await projects.update({
    projectId: '<PROJECT_ID>',
    name: '<NAME>',
    description: '<DESCRIPTION>',
    logo: '<LOGO>',
    url: 'https://example.com',
    legalName: '<LEGAL_NAME>',
    legalCountry: '<LEGAL_COUNTRY>',
    legalState: '<LEGAL_STATE>',
    legalCity: '<LEGAL_CITY>',
    legalAddress: '<LEGAL_ADDRESS>',
    legalTaxId: '<LEGAL_TAX_ID>'
});

console.log(result);
