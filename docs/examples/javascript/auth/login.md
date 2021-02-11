let sdk = new Appwrite();

sdk
    .setProject('')
;

/**
 * Will redirect to relevant page
 *  depends on the operation result
 */
sdk.auth.login(
    'email@example.com',
    'password',
    'http://example.com/success', // required for Web SDK
    'http://example.com/failure' // required for Web SDK
);