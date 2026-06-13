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
      question: 'Can I upload large files?',
      answer:
        'Yes. Storage supports chunked uploads for large files through the SDKs and console.',
    },
  ],
  cta: {
    title: 'Start building with Storage',
    description: 'Create a bucket, upload your first file, and generate a preview URL in minutes.',
  },
}
