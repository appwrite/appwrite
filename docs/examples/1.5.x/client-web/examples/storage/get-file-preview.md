import { Client, Storage, ImageGravity, ImageFormat } from "appwrite";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const storage = new Storage(client);

const result = storage.getFilePreview(
    '<BUCKET_ID>', // bucketId
    '<FILE_ID>', // fileId
    0, // width (optional)
    0, // height (optional)
    ImageGravity.Center, // gravity (optional)
    0, // quality (optional)
    0, // borderWidth (optional)
    '', // borderColor (optional)
    0, // borderRadius (optional)
    0, // opacity (optional)
    -360, // rotation (optional)
    '', // background (optional)
    ImageFormat.Jpg // output (optional)
);

console.log(result);
