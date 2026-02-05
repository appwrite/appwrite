```javascript
import { Client, Sites, Frameworks, UseCases } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const sites = new Sites(client);

const result = await sites.listTemplates({
    frameworks: [Frameworks.Analog], // optional
    useCases: [UseCases.Portfolio], // optional
    limit: 1, // optional
    offset: 0 // optional
});

console.log(result);
```
