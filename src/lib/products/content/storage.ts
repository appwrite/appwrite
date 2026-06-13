import {
  CloudUpload,
  Download,
  Image,
  LockKeyhole,
  ScanSearch,
  ShieldCheck,
} from 'lucide-react'
import type { ProductPageContent } from '@/lib/products/types'

export const storageProductContent: ProductPageContent = {
  id: 'storage',
  metaDescription:
    'Store, manage, and deliver files with Appwrite Storage. Buckets, permissions, image transforms, and secure downloads.',
  hero: {
    title: 'File storage with delivery built in',
    description:
      'Upload, organize, and serve images, videos, documents, and other assets with encryption, compression, and on-the-fly transformations.',
    stats: [
      { value: 'Buckets', label: 'Isolated file namespaces' },
      { value: 'Transforms', label: 'Image preset wizard' },
      { value: 'Compression', label: 'gzip and zstd buckets' },
      { value: 'Security', label: 'Per-file permissions' },
    ],
  },
  capabilities: {
    title: 'Store files the way your app needs',
    description:
      'From user uploads to static assets, Storage handles ingestion, access control, and delivery without a separate CDN vendor.',
    items: [
      {
        title: 'Bucket organization',
        description:
          'Group files into buckets with their own limits, formats, and permission policies.',
        icon: CloudUpload,
      },
      {
        title: 'Secure access',
        description:
          'Restrict reads and writes to users, teams, roles, or custom permission strings.',
        icon: LockKeyhole,
      },
      {
        title: 'Image transformations',
        description:
          'Resize, crop, and optimize images on delivery without storing every variant.',
        icon: Image,
      },
      {
        title: 'Large file support',
        description:
          'Upload big files with chunked uploads and track progress from client SDKs.',
        icon: Download,
      },
      {
        title: 'Preview URLs',
        description:
          'Generate preview links for images, videos, and documents with access controls.',
        icon: ScanSearch,
      },
      {
        title: 'Encryption',
        description:
          'Keep sensitive assets protected with encryption in transit and at rest on Cloud.',
        icon: ShieldCheck,
      },
    ],
  },
  visual: {
    title: 'Upload, transform, and deliver',
    description:
      'Manage buckets in the console, preview transformations, and serve files through Appwrite APIs and CDN endpoints.',
  },
  uniqueSections: [
    {
      type: 'steps',
      title: 'The file pipeline',
      description:
        'Storage handles each stage from upload to delivery so you can focus on product features.',
      items: [
        {
          title: 'Upload to buckets',
          description:
            'Accept files from clients or server SDKs with metadata, custom IDs, and permission rules.',
        },
        {
          title: 'Transform on demand',
          description:
            'Apply image presets for thumbnails, hero images, and responsive layouts without duplicate files.',
        },
        {
          title: 'Deliver securely',
          description:
            'Serve files through permission-aware URLs and CDN-backed endpoints for low latency.',
        },
      ],
    },
    {
      type: 'feature-grid',
      title: 'Built for production workloads',
      description:
        'Operational controls that keep file storage predictable as traffic grows.',
      items: [
        {
          title: 'Compression',
          description: 'Store bucket files with gzip or zstd compression at rest.',
          icon: Download,
        },
        {
          title: 'File metadata',
          description: 'Store custom attributes alongside files for search and filtering.',
          icon: ScanSearch,
        },
        {
          title: 'Bucket security',
          description: 'Configure file-level and bucket-level rules from the console.',
          icon: LockKeyhole,
        },
        {
          title: 'SDK support',
          description: 'Upload and download from web, mobile, and server SDKs with the same APIs.',
          icon: CloudUpload,
        },
      ],
      columns: 2,
      muted: true,
    },
  ],
  integrations: {
    title: 'Works with the Appwrite platform',
    description:
      'Storage pairs naturally with databases, auth, and compute in the same project.',
    items: [
      {
        productId: 'databases',
        title: 'Metadata in tables',
        description: 'Store file IDs and metadata in database rows for structured queries.',
      },
      {
        productId: 'auth',
        title: 'User-owned files',
        description: 'Limit uploads and downloads to the signed-in user or their team.',
      },
      {
        productId: 'functions',
        title: 'Processing pipelines',
        description: 'Run Functions on upload events to transcode, validate, or enrich files.',
      },
      {
        productId: 'sites',
        title: 'Static assets',
        description: 'Serve marketing and app assets from Storage-backed URLs in Sites apps.',
      },
    ],
  },
  faq: [
    {
      question: 'How is Storage different from Databases?',
      answer:
        'Storage is for binary files like images and PDFs. Databases store structured rows and fields. Most apps use both together.',
    },
    {
      question: 'Can I transform images without storing multiple copies?',
      answer:
        'Yes. Request transformations in preview URLs to resize and optimize images on the fly.',
    },
    {
      question: 'Are files private by default?',
      answer:
        'Bucket and file permissions are configurable. You can require authentication for reads, writes, or both.',
    },
    {
      question: 'Does Storage work with self-hosted Appwrite?',
      answer:
        'Yes. Self-hosted projects use the same Storage APIs with your configured storage adapter.',
    },
  ],
  cta: {
    title: 'Start building with Storage',
    description: 'Create a bucket, upload your first file, and generate a preview URL in minutes.',
  },
}
