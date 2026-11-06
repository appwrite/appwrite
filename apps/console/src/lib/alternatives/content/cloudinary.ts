import type { AlternativeContent } from '@/lib/alternatives/types'

export const cloudinaryAlternativeContent: AlternativeContent = {
  comparison: [
    {
      title: 'Pricing',
      rows: [
        {
          label: 'First paid plan',
          appwrite: 'From $25/mo',
          competitor: { value: '$89/mo', note: 'Plus, billed yearly ($99 monthly)' },
        },
        {
          label: 'Billing unit',
          appwrite: { value: 'Allowance per resource', note: 'Storage, bandwidth, and images apart' },
          competitor: { value: 'Shared credits', note: 'Storage, bandwidth, and transformations draw from one pool' },
        },
        {
          label: 'Image transformations',
          appwrite: { value: 'Per origin image', note: '100 on Pro, then $5 per 1,000' },
          competitor: { value: 'Per derived asset', note: '1 credit = 1,000 transformations' },
        },
        {
          label: 'Storage and bandwidth',
          appwrite: { value: '150GB and 2TB', note: 'Included on Pro' },
          competitor: { value: '225 credits on Plus', note: '1 credit = 1GB of either' },
        },
      ],
    },
    {
      title: 'Files',
      rows: [
        {
          label: 'Max upload size',
          appwrite: { value: '5GB', note: 'On Pro, 50MB on Free' },
          competitor: { value: '20MB images', note: 'On Plus, 10MB Free, 40MB Advanced' },
        },
        {
          label: 'Access control',
          appwrite: { value: 'User, team, and role permissions', note: 'Private by default' },
          competitor: { value: 'partial', note: 'Token authentication from Advanced' },
        },
        { label: 'S3-compatible API', appwrite: true, competitor: false },
      ],
    },
    {
      title: 'Images',
      rows: [
        { label: 'Resize, crop, quality, and format', appwrite: true, competitor: true },
        {
          label: 'Smart crop',
          appwrite: { value: 'gravity=auto', note: 'Face and subject aware' },
          competitor: { value: 'g_auto', note: 'AI-based' },
        },
        {
          label: 'Output formats',
          appwrite: 'JPG, PNG, GIF, WebP, AVIF, HEIC',
          competitor: true,
        },
        {
          label: 'Video transcoding and processing',
          appwrite: { value: 'soon', note: 'With Appwrite Videos' },
          competitor: true,
        },
      ],
    },
    {
      title: 'Platform',
      rows: [
        {
          label: 'Auth, database, and functions in the same project',
          appwrite: true,
          competitor: false,
        },
        {
          label: 'Open source and self-hostable',
          appwrite: { value: true, note: 'Transformations are free when self-hosted' },
          competitor: false,
        },
        {
          label: 'Hosting, messaging, and realtime',
          appwrite: true,
          competitor: false,
        },
      ],
    },
  ],
  fairPlay: {
    title: 'When Cloudinary might still fit',
    description:
      'Cloudinary is a mature, dedicated media platform. If media is the whole product, it may be worth the premium.',
    points: [
      'You need adaptive video streaming with HLS and DASH and video analytics today, before Appwrite Videos launches.',
      'Marketing and content teams need a digital asset management library with tagging and approvals.',
      'You want generative AI edits like background removal and generative fill through URL parameters.',
      'You need multi-CDN delivery under an enterprise contract.',
    ],
  },
  related: [
    {
      kind: 'blog',
      title: 'Appwrite vs Cloudinary: Storage and image handling compared',
      description: 'Fit, not quality: when each one makes sense.',
      href: '/blog/post/appwrite-vs-cloudinary',
    },
    {
      kind: 'blog',
      title: 'Storage previews vs SSR image optimization: when to use which',
      description: 'One preview URL for web, React Native, and Flutter.',
      href: '/blog/post/storage-previews-vs-ssr-image-optimization',
    },
    {
      kind: 'blog',
      title: 'Automatic image cropping in Appwrite with AutoGravity',
      description: 'Face detection first, then saliency, with open models.',
      href: '/blog/post/introducing-autogravity',
    },
    {
      kind: 'blog',
      title: 'Announcing HEIC and AVIF support',
      description: 'Upload any format and convert at serve time.',
      href: '/blog/post/new-image-formats-avif-heic',
    },
    {
      kind: 'product',
      title: 'Appwrite Storage',
      description: 'Upload, transform, and deliver files on CDN.',
      href: '/products/storage',
    },
    {
      kind: 'docs',
      title: 'Image transformations',
      description: 'Every preview parameter and its range.',
      href: '/docs/products/storage/images',
    },
  ],
  faq: [
    {
      question: 'Is Appwrite better than Cloudinary?',
      answer:
        'For app media, yes. Appwrite Storage gives you uploads up to 5GB, private files with per-user and per-team permissions, and on-the-fly image transformations billed per origin image, inside an open-source backend that also includes auth, databases, functions, realtime, and hosting. Cloudinary is a separate, proprietary media service priced in shared credits.',
    },
    {
      question: 'What is the best open-source alternative to Cloudinary?',
      answer:
        'Appwrite is the best open-source alternative to Cloudinary for apps. Appwrite Storage resizes, crops with AutoGravity, and converts images to AVIF, WebP, or HEIC from a single preview URL, and you can run it on Appwrite Cloud or self-host it with Docker, where transformations are free.',
      links: [{ label: 'Appwrite Storage', href: '/products/storage' }],
    },
    {
      question: 'Is Appwrite Storage a good Cloudinary alternative?',
      answer:
        'Yes, for app media. Appwrite Storage handles uploads up to 5GB, permissions per user and team, encryption, compression, and on-the-fly image transformations with smart cropping, all inside the same backend as your auth and database.',
    },
    {
      question: 'How are image transformations billed?',
      answer:
        'Appwrite bills per origin image, not per variant. One image served in 20 sizes and formats counts once. Pro includes 100 origin images per month, then $5 per 1,000, and transformations are free when you self-host.',
      links: [{ label: 'Pricing', href: '/pricing' }],
    },
    {
      question: 'Which image formats and parameters are supported?',
      answer:
        'Output JPG, PNG, GIF, WebP, AVIF, or HEIC at request time, from any common upload format. Set width, height, gravity (including auto), quality, border, border radius, opacity, rotation, and background color in the preview URL.',
      links: [{ label: 'Image transformations', href: '/docs/products/storage/images' }],
    },
    {
      question: 'Can I keep files private?',
      answer:
        'Yes. Files are private by default. Grant read access to any user, a specific user, a team, or a team role, and create file tokens with an optional expiry to share a single file without a session.',
      links: [
        { label: 'Permissions', href: '/docs/products/storage/permissions' },
        { label: 'File tokens', href: '/docs/products/storage/file-tokens' },
      ],
    },
    {
      question: 'Can I use S3 tools with Appwrite Storage?',
      answer:
        'Yes. Storage exposes an S3-compatible endpoint with SigV4 signing, so tools like the AWS CLI and rclone can read and write your buckets.',
      links: [{ label: 'S3-compatible API', href: '/docs/products/storage/s3' }],
    },
    {
      question: 'Does Appwrite handle video like Cloudinary?',
      answer:
        'Appwrite Storage already stores and delivers video files of up to 5GB on Pro. Video transcoding and processing are coming soon with Appwrite Videos, so images and video will live in the same backend as your users and data.',
    },
  ],
  sources: [
    { label: 'Cloudinary pricing', href: 'https://cloudinary.com/pricing' },
    { label: 'Cloudinary plan comparison', href: 'https://cloudinary.com/pricing/compare-plans' },
    {
      label: 'Cloudinary transformation counts',
      href: 'https://cloudinary.com/documentation/transformation_counts',
    },
  ],
}
