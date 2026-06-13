import type { ProductPageContent } from '@/lib/products/types'

export const storageProductContent: ProductPageContent = {
  id: 'storage',
  metaDescription:
    'Store, manage, and deliver files with Appwrite Storage. Built-in CDN, regional caching, S3-compatible access, compression, encryption, on-the-fly transforms, file tokens, and secure downloads.',
  hero: {
    title: 'File storage with delivery built in',
    description:
      'Upload, organize, and serve images, videos, documents, and other assets through Appwrite CDN. Resize, crop, and convert formats on the fly, compress and encrypt at the bucket level, and share with granular permissions.',
    stats: [
      { value: 'CDN', label: '120+ edge locations' },
      { value: 'Transforms', label: 'On-the-fly delivery' },
      { value: 'S3', label: 'Compatible object access' },
      { value: 'Compression', label: 'gzip and zstd buckets' },
      { value: 'Encryption', label: 'At-rest protection' },
    ],
  },
  faq: [
    {
      question: 'How is Storage different from Databases?',
      answer:
        'Storage is for binary files like images, videos, and PDFs. Databases store structured rows and fields. Most apps use both together: Storage for assets and Databases for metadata and relationships.',
      links: [
        { label: 'Storage overview', href: '/docs/products/storage' },
        { label: 'Databases overview', href: '/docs/products/databases' },
      ],
    },
    {
      question: 'Can I transform images without storing multiple copies?',
      answer:
        'Yes. Request transformations through the preview endpoint to resize, crop, convert format, and adjust quality on the fly. Keep one original upload and let Appwrite generate variants on demand.',
      links: [{ label: 'Image transformations', href: '/docs/products/storage/images' }],
    },
    {
      question: 'Is CDN delivery included with Storage?',
      answer:
        'Yes. Storage files and transformed previews are served through Appwrite CDN with 120+ edge locations. Transformed images are cached in your project region, so repeat requests skip re-processing before reaching the edge.',
      links: [
        { label: 'CDN overview', href: '/docs/products/network/cdn' },
        { label: 'Caching', href: '/docs/products/network/caching' },
      ],
    },
    {
      question: 'Does Storage support file compression?',
      answer:
        'Yes. Enable gzip or zstd compression per bucket from Settings. Compression applies to new uploads and helps reduce storage and bandwidth costs. Files larger than 20 MB skip compression even when enabled.',
      links: [{ label: 'Bucket compression', href: '/docs/products/storage/buckets' }],
    },
    {
      question: 'Can I encrypt files in Storage?',
      answer:
        'Yes. Turn on encryption per bucket from Settings so new files are stored encrypted at rest. If files are leaked, encrypted objects cannot be read without your keys. Files larger than 20 MB skip encryption even when enabled.',
      links: [{ label: 'Bucket encryption', href: '/docs/products/storage/buckets' }],
    },
    {
      question: 'Are files private by default?',
      answer:
        'Yes. Buckets and files have no permissions by default, so access is denied until you grant read, create, update, or delete to users, teams, or roles. Enable file security on a bucket to set per-file permissions on top of bucket defaults.',
      links: [{ label: 'Storage permissions', href: '/docs/products/storage/permissions' }],
    },
    {
      question: 'How do file tokens work for public sharing?',
      answer:
        'File tokens are secrets attached to a file that authorize preview, view, or download without session cookies. Create tokens from the Console or Server SDK, set an optional expiry, and share the URL with anyone. This avoids third-party cookie issues in embedded or cross-domain apps.',
      links: [{ label: 'File tokens', href: '/docs/products/storage/file-tokens' }],
    },
    {
      question: 'Can I upload large files?',
      answer:
        'Yes. Storage supports chunked uploads for large files through the SDKs and Console. Configure maximum file size per bucket and use resumable uploads when transferring big assets.',
      links: [{ label: 'Upload and download', href: '/docs/products/storage/upload-download' }],
    },
    {
      question: 'Does Storage support the S3 API?',
      answer:
        'Yes. Appwrite Storage exposes a project-scoped HTTPS endpoint with SigV4-compatible signing. Copy the endpoint, access key, and secret from the Connect tab in your project to attach buckets to rclone, Terraform, or other S3 tooling without rebuilding upload pipelines.',
      links: [{ label: 'Storage overview', href: '/docs/products/storage' }],
    },
  ],
  cta: {
    title: 'Start building with Storage',
    description: 'Create a bucket, upload your first file, and generate a preview URL in minutes.',
  },
}
