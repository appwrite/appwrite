import { Client, Avatars, CreditCard } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const avatars = new Avatars(client);

const result = avatars.getCreditCard({
    code: CreditCard.AmericanExpress,
    width: 0,
    height: 0,
    quality: -1
});

console.log(result);
