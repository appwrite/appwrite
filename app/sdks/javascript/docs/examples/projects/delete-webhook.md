let sdk = new Appwrite();

sdk
    .setProject('')
    .setKey('')
;

let promise = sdk.projects.deleteWebhook('[PROJECT_ID]', '[WEBHOOK_ID]');

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});