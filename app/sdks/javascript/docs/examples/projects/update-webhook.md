let sdk = new Appwrite();

sdk
    .setProject('')
    .setKey('')
;

let promise = sdk.projects.updateWebhook('[PROJECT_ID]', '[WEBHOOK_ID]', '[NAME]', [], '[URL]', 0);

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});