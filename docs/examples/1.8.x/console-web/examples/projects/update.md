import { Client, Projects } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const projects = new Projects(client);

const result = await projects.update({
    projectId: '<PROJECT_ID>',
    name: '<NAME>',
    description: '<DESCRIPTION>', // optional
    logo: '<LOGO>', // optional
    url: 'https://example.com', // optional
    legalName: '<LEGAL_NAME>', // optional
    legalCountry: '<LEGAL_COUNTRY>', // optional
    legalState: '<LEGAL_STATE>', // optional
    legalCity: '<LEGAL_CITY>', // optional
    legalAddress: '<LEGAL_ADDRESS>', // optional
    legalTaxId: '<LEGAL_TAX_ID>' // optional
});

console.log(result);
