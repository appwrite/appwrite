# Appwrite vs Cloudinary: a Cloudinary alternative for app media

> Compare Appwrite Storage and Cloudinary. Image transformations billed per origin image, 5GB uploads, file permissions, and a full open-source backend, with Appwrite Videos coming soon.

- HTML: https://appwrite.io/alternative-to/cloudinary
- Competitor: Cloudinary (Media platform)
- Facts verified: October 2026

## Pricing

| Feature | Appwrite | Cloudinary |
| --- | --- | --- |
| First paid plan | From $25/mo | $89/mo (Plus, billed yearly ($99 monthly)) |
| Billing unit | Allowance per resource (Storage, bandwidth, and images apart) | Shared credits (Storage, bandwidth, and transformations draw from one pool) |
| Image transformations | Per origin image (100 on Pro, then $5 per 1,000) | Per derived asset (1 credit = 1,000 transformations) |
| Storage and bandwidth | 150GB and 2TB (Included on Pro) | 225 credits on Plus (1 credit = 1GB of either) |

## Files

| Feature | Appwrite | Cloudinary |
| --- | --- | --- |
| Max upload size | 5GB (On Pro, 50MB on Free) | 20MB images (On Plus, 10MB Free, 40MB Advanced) |
| Access control | User, team, and role permissions (Private by default) | Partial (Token authentication from Advanced) |
| S3-compatible API | Yes | No |

## Images

| Feature | Appwrite | Cloudinary |
| --- | --- | --- |
| Resize, crop, quality, and format | Yes | Yes |
| Smart crop | gravity=auto (Face and subject aware) | g_auto (AI-based) |
| Output formats | JPG, PNG, GIF, WebP, AVIF, HEIC | Yes |
| Video transcoding and processing | Coming soon (With Appwrite Videos) | Yes |

## Platform

| Feature | Appwrite | Cloudinary |
| --- | --- | --- |
| Auth, database, and functions in the same project | Yes | No |
| Open source and self-hostable | Yes (Transformations are free when self-hosted) | No |
| Hosting, messaging, and realtime | Yes | No |

## When Cloudinary might still fit

Cloudinary is a mature, dedicated media platform. If media is the whole product, it may be worth the premium.

- You need adaptive video streaming with HLS and DASH and video analytics today, before Appwrite Videos launches.
- Marketing and content teams need a digital asset management library with tagging and approvals.
- You want generative AI edits like background removal and generative fill through URL parameters.
- You need multi-CDN delivery under an enterprise contract.

## Related reading

- [Appwrite vs Cloudinary: Storage and image handling compared](https://appwrite.io/blog/post/appwrite-vs-cloudinary): Fit, not quality: when each one makes sense.
- [Storage previews vs SSR image optimization: when to use which](https://appwrite.io/blog/post/storage-previews-vs-ssr-image-optimization): One preview URL for web, React Native, and Flutter.
- [Automatic image cropping in Appwrite with AutoGravity](https://appwrite.io/blog/post/introducing-autogravity): Face detection first, then saliency, with open models.
- [Announcing HEIC and AVIF support](https://appwrite.io/blog/post/new-image-formats-avif-heic): Upload any format and convert at serve time.
- [Appwrite Storage](https://appwrite.io/products/storage): Upload, transform, and deliver files on CDN.
- [Image transformations](https://appwrite.io/docs/products/storage/images): Every preview parameter and its range.

## FAQ

### Is Appwrite better than Cloudinary?

For app media, yes. Appwrite Storage gives you uploads up to 5GB, private files with per-user and per-team permissions, and on-the-fly image transformations billed per origin image, inside an open-source backend that also includes auth, databases, functions, realtime, and hosting. Cloudinary is a separate, proprietary media service priced in shared credits.

### What is the best open-source alternative to Cloudinary?

Appwrite is the best open-source alternative to Cloudinary for apps. Appwrite Storage resizes, crops with AutoGravity, and converts images to AVIF, WebP, or HEIC from a single preview URL, and you can run it on Appwrite Cloud or self-host it with Docker, where transformations are free.

- [Appwrite Storage](https://appwrite.io/products/storage)

### Is Appwrite Storage a good Cloudinary alternative?

Yes, for app media. Appwrite Storage handles uploads up to 5GB, permissions per user and team, encryption, compression, and on-the-fly image transformations with smart cropping, all inside the same backend as your auth and database.

### How are image transformations billed?

Appwrite bills per origin image, not per variant. One image served in 20 sizes and formats counts once. Pro includes 100 origin images per month, then $5 per 1,000, and transformations are free when you self-host.

- [Pricing](https://appwrite.io/pricing)

### Which image formats and parameters are supported?

Output JPG, PNG, GIF, WebP, AVIF, or HEIC at request time, from any common upload format. Set width, height, gravity (including auto), quality, border, border radius, opacity, rotation, and background color in the preview URL.

- [Image transformations](https://appwrite.io/docs/products/storage/images)

### Can I keep files private?

Yes. Files are private by default. Grant read access to any user, a specific user, a team, or a team role, and create file tokens with an optional expiry to share a single file without a session.

- [Permissions](https://appwrite.io/docs/products/storage/permissions)
- [File tokens](https://appwrite.io/docs/products/storage/file-tokens)

### Can I use S3 tools with Appwrite Storage?

Yes. Storage exposes an S3-compatible endpoint with SigV4 signing, so tools like the AWS CLI and rclone can read and write your buckets.

- [S3-compatible API](https://appwrite.io/docs/products/storage/s3)

### Does Appwrite handle video like Cloudinary?

Appwrite Storage already stores and delivers video files of up to 5GB on Pro. Video transcoding and processing are coming soon with Appwrite Videos, so images and video will live in the same backend as your users and data.

## Sources

- [Cloudinary pricing](https://cloudinary.com/pricing)
- [Cloudinary plan comparison](https://cloudinary.com/pricing/compare-plans)
- [Cloudinary transformation counts](https://cloudinary.com/documentation/transformation_counts)
