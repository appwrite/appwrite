let sdk = new Appwrite();

sdk
    .setProject('')
    .setKey('')
;

let promise = sdk.projects.createWebhook('[PROJECT_ID]', '[NAME]', [], '[URL]', 0);

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});