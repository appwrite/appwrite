const sdk = require('node-appwrite');

const client = new sdk.Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2') // Your project ID
    .setSession(''); // The user session to authenticate with

const storage = new sdk.Storage(client);

const result = await storage.getFilePreview(
    '<BUCKET_ID>', // bucketId
    '<FILE_ID>', // fileId
    0, // width (optional)
    0, // height (optional)
    sdk.ImageGravity.Center, // gravity (optional)
    0, // quality (optional)
    0, // borderWidth (optional)
    '', // borderColor (optional)
    0, // borderRadius (optional)
    0, // opacity (optional)
    -360, // rotation (optional)
    '', // background (optional)
    sdk.ImageFormat.Jpg // output (optional)
);
