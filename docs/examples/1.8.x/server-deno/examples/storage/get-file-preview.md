import { Client, Storage, ImageGravity, ImageFormat } from "https://deno.land/x/appwrite/mod.ts";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setSession(''); // The user session to authenticate with

const storage = new Storage(client);

const result = storage.getFilePreview({
    bucketId: '<BUCKET_ID>',
    fileId: '<FILE_ID>',
    width: 0,
    height: 0,
    gravity: ImageGravity.Center,
    quality: -1,
    borderWidth: 0,
    borderColor: '',
    borderRadius: 0,
    opacity: 0,
    rotation: -360,
    background: '',
    output: ImageFormat.Jpg,
    token: '<TOKEN>'
});
