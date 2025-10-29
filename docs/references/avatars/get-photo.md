Use this endpoint to fetch a user's photo from Gravatar based on their email address. The API automatically generates the Gravatar URL using the user's email hash and provides standard image cropping options.

When width and height are specified, the image is resized accordingly. If both dimensions are 0, the API provides an image at original size. If dimensions are not specified, the default size is 100x100px.

This endpoint requires a valid user email address and will return a 404 if no Gravatar image is found for the user.
