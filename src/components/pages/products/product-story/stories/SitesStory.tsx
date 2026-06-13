import { GitBranch, Globe, Layers, Rocket } from 'lucide-react'
import { FrameworkIcon } from '@/components/global/shared/FrameworkIcon'
import { StoryAnimated, StoryAnimatedProgress } from '@/components/pages/products/product-story/StoryAnimated'
import {
  ProductStoryGrid,
  type ProductStoryFeature,
} from '@/components/pages/products/product-story/ProductStoryGrid'
import { StorySitePreview } from '@/components/pages/products/product-story/StorySitePreview'
import { StoryField, StoryOptionCard } from '@/components/pages/products/product-story/shared'

const BUILD_LOG_LINES = [
  '$ npm install',
  'added 842 packages in 6s',
  '$ npm run build',
  'Creating an optimized production build...',
  'Compiled successfully',
] as const

const FEATURES: ProductStoryFeature[] = [
  {
    id: 'git',
    title: 'Git repositories',
    description: 'Connect GitHub or GitLab and deploy on every push to your production branch.',
    icon: GitBranch,
    className: 'lg:col-span-6',
    content: (
      <div className="space-y-3">
        <StoryAnimated delayMs={0}>
          <StoryOptionCard
            selected
            title="Connect Git repository"
            description="Deploy on every push to your production branch."
            icon={<GitBranch className="size-4 text-muted-foreground" aria-hidden />}
          />
        </StoryAnimated>
        <StoryAnimated delayMs={120}>
          <StoryField label="Production branch" value="main" active />
        </StoryAnimated>
        <StoryAnimated delayMs={200}>
          <StoryField label="Repository" value="github.com/acme/storefront" mono />
        </StoryAnimated>
      </div>
    ),
  },
  {
    id: 'framework',
    title: 'Framework detection',
    description: 'Auto-detect install, build, and output settings for popular frameworks.',
    icon: Layers,
    className: 'lg:col-span-6',
    content: (
      <div className="space-y-3">
        <StoryAnimated delayMs={0}>
          <div className="flex items-center gap-2 rounded-lg border border-border bg-muted/10 px-3 py-2">
            <FrameworkIcon framework="nextjs" size="md" />
            <div>
              <p className="text-[12px] font-semibold text-foreground">Next.js</p>
              <p className="text-[11px] text-muted-foreground">SSR adapter selected</p>
            </div>
          </div>
        </StoryAnimated>
        <StoryAnimated delayMs={120}>
          <StoryField label="Build command" value="npm run build" mono active />
        </StoryAnimated>
        <StoryAnimated delayMs={200}>
          <StoryField label="Output directory" value="./.next" mono />
        </StoryAnimated>
      </div>
    ),
  },
  {
    id: 'env',
    title: 'Environment variables',
    description: 'Inject secrets and project config at build time and runtime without committing them.',
    icon: Rocket,
    className: 'lg:col-span-6',
    content: (
      <div className="space-y-2">
        <StoryAnimated delayMs={0}>
          <StoryField label="APPWRITE_ENDPOINT" value="https://cloud.appwrite.io/v1" mono />
        </StoryAnimated>
        <StoryAnimated delayMs={100}>
          <StoryField label="APPWRITE_PROJECT_ID" value="acme-production" mono active />
        </StoryAnimated>
        <StoryAnimated delayMs={200}>
          <StoryField label="APPWRITE_API_KEY" value="••••••••••••••••" mono />
        </StoryAnimated>
      </div>
    ),
  },
  {
    id: 'build',
    title: 'Build logs',
    description: 'Watch install and compile output for every deployment in the console.',
    icon: Layers,
    className: 'lg:col-span-6',
    content: (
      <div className="space-y-3">
        <StoryAnimated delayMs={0}>
          <StoryAnimatedProgress target={100} durationMs={2800} />
        </StoryAnimated>
        <div className="space-y-1 rounded-lg border border-border bg-muted/10 p-3 font-mono text-[10px] sm:text-[11px]">
          {BUILD_LOG_LINES.map((line, index) => (
            <StoryAnimated key={line} delayMs={200 + index * 180}>
              <p
                className={
                  index === BUILD_LOG_LINES.length - 1
                    ? 'text-emerald-600 dark:text-emerald-400'
                    : 'text-muted-foreground'
                }
              >
                {line}
              </p>
            </StoryAnimated>
          ))}
        </div>
      </div>
    ),
  },
  {
    id: 'preview',
    title: 'Production & preview URLs',
    description: 'Every deployment gets a unique preview URL before you promote to production.',
    icon: Globe,
    className: 'lg:col-span-12',
    tall: true,
    content: (
      <div className="grid h-full gap-3 lg:grid-cols-[minmax(0,1.2fr)_minmax(0,0.8fr)]">
        <StorySitePreview
          productionUrl="storefront.appwrite.network"
          previewUrl="8f2a91c.preview.appwrite.network"
          siteName="Storefront"
        />
        <div className="space-y-3">
          <StoryAnimated delayMs={200}>
            <StoryField label="Production URL" value="storefront.appwrite.network" mono active />
          </StoryAnimated>
          <StoryAnimated delayMs={320}>
            <StoryField label="Preview URL" value="8f2a91c.preview.appwrite.network" mono />
          </StoryAnimated>
          <StoryAnimated delayMs={440}>
            <StoryField label="Framework" value="Next.js (SSR)" />
          </StoryAnimated>
        </div>
      </div>
    ),
  },
]

export function SitesStory() {
  return <ProductStoryGrid features={FEATURES} />
}
