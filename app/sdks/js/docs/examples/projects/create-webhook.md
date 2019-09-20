let sdk = new Appwrite();

sdk
    setProject('')
;

let promise = sdk.projects.createWebhook('[PROJECT_ID]', '[NAME]', [], '[URL]', 1);

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});