import { CloudUpload, ImageIcon, LockKeyhole, ScanSearch, ShieldCheck } from 'lucide-react'
import { StoryAnimated, StoryAnimatedProgress } from '@/components/pages/products/product-story/StoryAnimated'
import {
  ProductStoryGrid,
  type ProductStoryFeature,
} from '@/components/pages/products/product-story/ProductStoryGrid'
import { StoryCodeBlock } from '@/components/pages/products/product-story/StoryCodeBlock'
import { StoryField } from '@/components/pages/products/product-story/shared'

const UPLOAD_CODE = [
  { text: 'const file = await storage.createFile({', tone: 'keyword' as const },
  { text: "  bucketId: 'product_images',", tone: 'string' as const },
  { text: "  fileId: ID.unique(),", tone: 'plain' as const },
  { text: '  file: input.files[0],', tone: 'plain' as const },
  { text: '});', tone: 'plain' as const },
]

const PREVIEW_CODE = [
  { text: 'const preview = storage.getFilePreview({', tone: 'plain' as const },
  { text: "  bucketId: 'product_images',", tone: 'string' as const },
  { text: '  width: 640, quality: 80,', tone: 'plain' as const },
  { text: "  output: 'webp',", tone: 'string' as const },
  { text: '});', tone: 'plain' as const },
]

const FEATURES: ProductStoryFeature[] = [
  {
    id: 'buckets',
    title: 'Bucket settings',
    description: 'Organize files by use case with size limits, encryption, and compression.',
    icon: CloudUpload,
    className: 'lg:col-span-6',
    content: (
      <div className="grid gap-3 sm:grid-cols-2">
        <StoryAnimated delayMs={0}>
          <StoryField label="Name" value="product-images" active />
        </StoryAnimated>
        <StoryAnimated delayMs={100}>
          <StoryField label="Bucket ID" value="product_images" mono />
        </StoryAnimated>
        <StoryAnimated delayMs={200}>
          <StoryField label="Max file size" value="10 MB" />
        </StoryAnimated>
        <StoryAnimated delayMs={300}>
          <StoryField label="Encryption" value="Enabled" />
        </StoryAnimated>
      </div>
    ),
  },
  {
    id: 'upload',
    title: 'Uploads & metadata',
    description: 'Accept files from client or server SDKs with custom attributes for search.',
    icon: ScanSearch,
    className: 'lg:col-span-6',
    content: (
      <div className="space-y-3">
        <StoryAnimated delayMs={0}>
          <div className="rounded-lg border border-dashed border-border bg-muted/10 px-4 py-6 text-center">
            <CloudUpload className="mx-auto size-5 text-muted-foreground" aria-hidden />
            <p className="mt-2 text-[12px] font-medium text-foreground">hero-banner.png</p>
            <p className="text-[11px] text-muted-foreground">1.8 MB</p>
          </div>
        </StoryAnimated>
        <StoryAnimatedProgress target={100} durationMs={2600} />
        <StoryAnimated delayMs={200}>
          <StoryField label="product_id" value="sku_1042" mono active />
        </StoryAnimated>
      </div>
    ),
  },
  {
    id: 'transforms',
    title: 'Image transforms',
    description: 'Resize, crop, and convert images on delivery without storing every variant.',
    icon: ImageIcon,
    className: 'lg:col-span-6',
    content: (
      <div className="grid gap-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
        <StoryAnimated delayMs={0}>
          <div className="flex aspect-[4/3] items-center justify-center rounded-lg border border-border bg-muted/20">
            <ImageIcon className="size-8 text-muted-foreground/40" aria-hidden />
          </div>
        </StoryAnimated>
        <div className="space-y-2">
          <StoryAnimated delayMs={120}>
            <StoryField label="Width" value="640" active />
          </StoryAnimated>
          <StoryAnimated delayMs={200}>
            <StoryField label="Quality" value="80" />
          </StoryAnimated>
          <StoryAnimated delayMs={280}>
            <StoryField label="Format" value="webp" />
          </StoryAnimated>
        </div>
      </div>
    ),
  },
  {
    id: 'preview-api',
    title: 'Preview API',
    description: 'Generate transformed preview URLs with permission-aware access controls.',
    icon: ImageIcon,
    className: 'lg:col-span-6',
    content: <StoryCodeBlock title="getFilePreview" language="TypeScript" lines={PREVIEW_CODE} />,
  },
  {
    id: 'security',
    title: 'Security pipeline',
    description: 'Enforce permissions, encrypt files, and trigger Functions on new uploads.',
    icon: ShieldCheck,
    className: 'lg:col-span-6',
    content: (
      <div className="grid gap-2">
        {[
          { icon: ShieldCheck, text: 'Encryption at rest on Cloud' },
          { icon: LockKeyhole, text: 'Read access limited to team members' },
          { icon: ScanSearch, text: 'Metadata indexed for search' },
          { icon: CloudUpload, text: 'Functions process new uploads' },
        ].map(({ icon: Icon, text }, index) => (
          <StoryAnimated key={text} delayMs={index * 100}>
            <p className="flex items-center gap-2 rounded-md border border-border bg-muted/10 px-3 py-2 text-[11px] text-muted-foreground sm:text-[12px]">
              <Icon className="size-3.5 shrink-0" aria-hidden />
              {text}
            </p>
          </StoryAnimated>
        ))}
      </div>
    ),
  },
  {
    id: 'sdk',
    title: 'Client SDK upload',
    description: 'Upload from web and mobile with permissions scoped to the signed-in user.',
    icon: CloudUpload,
    className: 'lg:col-span-6',
    content: <StoryCodeBlock title="createFile" language="TypeScript" lines={UPLOAD_CODE} lineDelayMs={35} />,
  },
]

export function StorageStory() {
  return <ProductStoryGrid features={FEATURES} />
}
