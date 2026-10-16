The Storage service allows you to manage your project files. Using the Storage service, you can upload, view, download, and query all your project files.

Files are managed using buckets. Storage buckets are similar to Collections we have in our [Databases](https://appwrite.io/docs/databases) service. The difference is, buckets also provide more power to decide what kinds of files, what sizes you want to allow in that bucket, whether or not to encrypt the files, scan with antivirus and more.

Using Appwrite permissions architecture, you can assign read or write access to each bucket or file in your project for either a specific user, team, user role, or even grant it with public access (`any`). You can learn more about [how Appwrite handles permissions and access control](https://appwrite.io/docs/permissions).

The preview endpoint allows you to generate preview images for your files. Using the preview endpoint, you can also manipulate the resulting image so that it will fit perfectly inside your app in terms of dimensions, file size, and style. The preview endpoint also allows you to change the resulting image file format for better compression or image quality for better delivery over the network.

On self-hosted instances, `_APP_STORAGE_LIMIT` sets the server maximum file size in bytes. Its default is 30 MB. Set it to `0` to disable the server maximum. A bucket's `maximumFileSize` of `0` adds no bucket-specific restriction; any positive server or plan maximum still applies. With both server and bucket maxima set to `0`, uploads have no configured file-size maximum. Chunked uploads still use the normal request-size limits, and available storage and storage-provider limits still apply.
