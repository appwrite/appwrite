import * as sdk from "https://deno.land/x/appwrite/mod.ts";

let client = new sdk.Client();

client
    .setEndpoint(Deno.env.get("APPWRITE_ENDPOINT") || '') // Your API Endpoint
    .setProject(Deno.env.get("APPWRITE_PROJECT") || '') // Your project ID
    .setKey(Deno.env.get("APPWRITE_SECRET") || '') // Your secret API key
;

let storage = new sdk.Storage(client);

// let result = storage.getFile(Deno.env.get("APPWRITE_FILEID"));

console.log(Deno.env.get("APPWRITE_FUNCTION_ID") || '');
console.log(Deno.env.get("APPWRITE_FUNCTION_NAME") || '');
console.log(Deno.env.get("APPWRITE_FUNCTION_TAG") || '');
console.log(Deno.env.get("APPWRITE_FUNCTION_TRIGGER") || '');
console.log(Deno.env.get("APPWRITE_FUNCTION_ENV_NAME") || '');
console.log(Deno.env.get("APPWRITE_FUNCTION_ENV_VERSION") || '');
// console.log(result['$id']"));
console.log(Deno.env.get("APPWRITE_FUNCTION_EVENT") || '');
console.log(Deno.env.get("APPWRITE_FUNCTION_EVENT_DATA") || '');