Create a video resource from an existing file in a storage bucket. The source file must be a video or audio file. Creating a video only stores the document in `pending` status; it does not start a download. Call the create-source endpoint next, then poll until `status` is `ready` before creating a timeline or rendition.

An optional `name` defaults to the source file name. Uploaded subtitle files override auto-extracted tracks for the same language once extraction has run as part of create-source.
