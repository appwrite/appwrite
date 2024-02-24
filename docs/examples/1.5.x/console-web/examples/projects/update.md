import { Client, Projects } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2'); // Your project ID

const projects = new Projects(client);

const result = await projects.update(
    '<PROJECT_ID>', // projectId
    '<NAME>', // name
    '<DESCRIPTION>', // description (optional)
    '<LOGO>', // logo (optional)
    'https://example.com', // url (optional)
    '<LEGAL_NAME>', // legalName (optional)
    '<LEGAL_COUNTRY>', // legalCountry (optional)
    '<LEGAL_STATE>', // legalState (optional)
    '<LEGAL_CITY>', // legalCity (optional)
    '<LEGAL_ADDRESS>', // legalAddress (optional)
    '<LEGAL_TAX_ID>' // legalTaxId (optional)
);

console.log(response);
