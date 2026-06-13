import type { ProductFeatureContent } from '@/lib/products/features/types'

export const storageProductFeatures: ProductFeatureContent[] = [
  {
    id: 'transforms',
    title: 'On-the-fly image transformations',
    description:
      'Use the preview endpoint to resize, crop, convert format, set quality, add borders, and rotate images on demand. No pre-processing pipeline or duplicate files.',
    docsHref: '/docs/products/storage/images',
    docsLabel: 'Image transforms docs',
  },
  {
    id: 'cdn',
    title: 'CDN delivery built in',
    description:
      'Every storage file and transformed preview is served through Appwrite CDN with 120+ edge locations worldwide. Transformed images are cached in your project region first, so repeat requests skip re-processing and reach users faster.',
    docsHref: '/docs/products/network/cdn',
    docsLabel: 'CDN docs',
  },
  {
    id: 'compression',
    title: 'Compression and image format optimization',
    description:
      'Lower storage costs, cut bandwidth, and speed up page loads without a separate media pipeline. Enable gzip or zstd per bucket to compress uploads automatically, then serve WebP, AVIF, and other modern formats from the preview endpoint. Keep one original upload and optimize file size every time you deliver it.',
    docsHref: '/docs/products/storage/buckets',
    docsLabel: 'Bucket settings docs',
  },
  {
    id: 'permissions',
    title: 'Granular permissions at bucket and file level',
    description:
      'Integrate Storage with Auth users, teams, and roles. Set bucket-wide defaults and per-file rules from the Console Security tab so the right people can read, create, update, or delete files.',
    docsHref: '/docs/products/storage/permissions',
    docsLabel: 'Permissions docs',
  },
  {
    id: 'encryption',
    title: 'Encryption at rest',
    description:
      'Turn on bucket encryption from Settings so new uploads are stored encrypted at rest. If files are exposed, encrypted objects stay unreadable without your project keys.',
    docsHref: '/docs/products/storage/buckets',
    docsLabel: 'Bucket settings docs',
  },
  {
    id: 'file-tokens',
    title: 'File tokens for expiring public links',
    description:
      'Share files with token-based preview, view, and download URLs that work without session cookies. Set an expiry date or keep links open-ended for external viewers.',
    docsHref: '/docs/products/storage/file-tokens',
    docsLabel: 'File tokens docs',
  },
  {
    id: 'buckets',
    title: 'Bucket-based file management at scale',
    description:
      'Organize uploads in isolated buckets with upload, download, list, and delete APIs. Browse files in the Console with search, pagination, and bulk operations.',
    docsHref: '/docs/products/storage/upload-download',
    docsLabel: 'Upload and download docs',
  },
  {
    id: 's3',
    title: 'S3-compatible object access',
    description:
      'Connect Appwrite Storage to rclone, Terraform, and custom pipelines with a project-scoped HTTPS endpoint and SigV4-compatible signing. Copy credentials from the Console Connect tab and keep your existing S3 workflows.',
    docsHref: '/docs/products/storage',
    docsLabel: 'Storage docs',
  },
  {
    id: 'transform-wizard',
    title: 'Image transform wizard and presets',
    description:
      'Build transforms visually in the Console, preview results live, and save presets for reuse across your team. Ship optimized images without writing transformation code.',
    docsHref: '/docs/products/storage/images',
    docsLabel: 'Image transforms docs',
    layout: 'stacked',
  },
]
