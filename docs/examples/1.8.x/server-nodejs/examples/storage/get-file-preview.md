const sdk = require('node-appwrite');

const client = new sdk.Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setSession(''); // The user session to authenticate with

const storage = new sdk.Storage(client);

const result = await storage.getFilePreview({
    bucketId: '<BUCKET_ID>',
    fileId: '<FILE_ID>',
    width: 0,
    height: 0,
    gravity: sdk.ImageGravity.Center,
    quality: -1,
    borderWidth: 0,
    borderColor: '',
    borderRadius: 0,
    opacity: 0,
    rotation: -360,
    background: '',
    output: sdk.ImageFormat.Jpg,
    token: '<TOKEN>'
});
