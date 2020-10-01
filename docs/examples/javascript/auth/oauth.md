## OAuth Example

```js
let sdk = new Appwrite();

sdk.setProject("");

/**
 * Will redirect to relevant page
 *  depends on the operation result
 */
sdk.auth.oauth(
  "facebook",
  "http://example.com/success",
  "http://example.com/failure"
);
```
