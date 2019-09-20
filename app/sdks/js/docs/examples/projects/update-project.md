let sdk = new Appwrite();

sdk
    setProject('')
;

let promise = sdk.projects.updateProject('[PROJECT_ID]', '[NAME]');

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});