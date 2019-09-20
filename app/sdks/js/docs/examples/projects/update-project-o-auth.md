let sdk = new Appwrite();

sdk
    setProject('')
;

let promise = sdk.projects.updateProjectOAuth('[PROJECT_ID]', 'bitbucket');

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});