import { Client, Proxy,  } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://example.com/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const proxy = new Proxy(client);

const result = await proxy.createRedirectRule(
    '', // domain
    'https://example.com', // url
    .MovedPermanently301 // statusCode
);

console.log(result);
