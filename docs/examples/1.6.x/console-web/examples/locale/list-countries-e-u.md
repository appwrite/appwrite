import { Client, Locale } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('&lt;YOUR_PROJECT_ID&gt;'); // Your project ID

const locale = new Locale(client);

const result = await locale.listCountriesEU();

console.log(result);
