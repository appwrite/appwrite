The Storage service allows you to manage your project files. Using the Storage service, you can upload, view, download, and query all your project files.

Files are managed using buckets. Storage buckets are similar to Collections we have in our [Databases](/docs/databases) service. The difference is, buckets also provide more power to decide what kinds of files, what sizes you want to allow in that bucket, whether or not to encrypt the files, scan with antivirus and more.

Using Appwrite permissions architecture, you can assign read or write access to each bucket or file in your project for either a specific user, team, user role, or even grant it with public access (`any`). You can learn more about [how Appwrite handles permissions and access control](/docs/permissions).

The preview endpoint allows you to generate preview images for your files. Using the preview endpoint, you can also manipulate the resulting image so that it will fit perfectly inside your app in terms of dimensions, file size, and style. The preview endpoint also allows you to change the resulting image file format for better compression or image quality for better delivery over the network.
