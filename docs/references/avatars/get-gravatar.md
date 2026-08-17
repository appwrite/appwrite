Use this endpoint to fetch a user's Gravatar avatar image. Gravatar is a globally-recognized avatar service that returns an avatar based on the SHA-256 hash of a user's email address.

Pass an `email` address to fetch the Gravatar for that address, or leave it empty to use the currently signed-in user's email. If neither an `email` param nor an active session is provided, the request will fail.

When the email has no associated Gravatar, the `default` parameter controls what is returned (e.g., a generated identicon, the mystery-person silhouette, or a `404` error).
