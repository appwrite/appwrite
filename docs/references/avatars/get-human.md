Use this endpoint to fetch a human avatar from supported providers. Pass one or more identifiers such as a GitHub username, an email address, or an email hash for Gravatar. When both `email` and `emailHash` are provided, `emailHash` takes precedence. Providers are queried in a fixed order and the first available image is returned.

When one dimension is specified and the other is 0, the image is scaled with preserved aspect ratio. If both dimensions are 0, the API provides an image at source quality. If dimensions are not specified, the default size of image returned is 400x400px.

This endpoint does not follow HTTP redirects.
