POST /v1/users/scrypt-modified HTTP/1.1
Host: cloud.appwrite.io
Content-Type: application/json
X-Appwrite-Response-Format: 1.5.0
X-Appwrite-Project: &lt;YOUR_PROJECT_ID&gt;
X-Appwrite-Key: &lt;YOUR_API_KEY&gt;

{
  "userId": "<USER_ID>",
  "email": "email@example.com",
  "password": "password",
  "passwordSalt": "<PASSWORD_SALT>",
  "passwordSaltSeparator": "<PASSWORD_SALT_SEPARATOR>",
  "passwordSignerKey": "<PASSWORD_SIGNER_KEY>",
  "name": "<NAME>"
}
