```javascript
const sdk = require('node-appwrite');

const client = new sdk.Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your secret API key

const sites = new sdk.Sites(client);

const result = await sites.createVcsDeployment({
    siteId: '<SITE_ID>',
    type: sdk.VCSReferenceType.Branch,
    reference: '<REFERENCE>',
    activate: false // optional
});
```
