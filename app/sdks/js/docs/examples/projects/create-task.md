let sdk = new Appwrite();

sdk
    setProject('')
;

let promise = sdk.projects.createTask('[PROJECT_ID]', '[NAME]', 'play', '', 1, 'GET', 'https://example.com');

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});