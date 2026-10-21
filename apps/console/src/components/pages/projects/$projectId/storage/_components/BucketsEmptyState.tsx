import type { ReactNode } from 'react'
import {
  FolderPlus,
  HardDrive,
  ImageIcon,
  Play,
  UploadCloud,
  Wand2,
} from 'lucide-react'
import {
  ProductEmptyStateCreateButton,
  ProductEmptyStateHero,
  ProductEmptyStateSteps,
  ProductEmptyStateVisual,
  type ProductEmptyStateStep,
} from '@/components/global/shared/ProductEmptyState'
import { Button } from '@/components/ui/button'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { getDocsPageUrl } from '@/lib/marketing/urls'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const STEPS: ProductEmptyStateStep[] = [
  {
    icon: FolderPlus,
    title: 'Create a bucket',
    description:
      'Each bucket has its own permissions, size limit, and allowed file types.',
  },
  {
    icon: UploadCloud,
    title: 'Upload files',
    description:
      'Upload from the console or from your app with the SDKs. Large files upload in chunks.',
  },
  {
    icon: Wand2,
    title: 'Transform images',
    description:
      'Resize, crop, and convert images on the fly with preview URLs.',
  },
]

function FileCard({
  extension,
  className,
  children,
}: {
  extension: string
  className?: string
  children: ReactNode
}) {
  return (
    <div
      className={cn(
        'absolute bottom-10 w-28 rounded-lg border border-border bg-card p-2 shadow-lg',
        className,
      )}
    >
      <div className="aspect-[4/3] overflow-hidden rounded-md">{children}</div>
      <div className="mt-2 flex items-center justify-between gap-2">
        <span className="h-1.5 w-12 rounded-full bg-muted-foreground/30" />
        <span className="rounded border border-border px-1 font-mono text-[9px] leading-4 text-muted-foreground">
          {extension}
        </span>
      </div>
    </div>
  )
}

/** Decorative files dropped into an upload zone, with a live upload and a transform. */
function UploadVisual() {
  return (
    <ProductEmptyStateVisual className="h-56 w-[440px]">
      <div className="absolute inset-x-8 bottom-0 flex h-28 items-end justify-center rounded-xl border-2 border-dashed border-foreground/15 bg-muted/20 pb-3">
        <span className="flex items-center gap-1.5 text-muted-foreground/70">
          <UploadCloud className="h-3.5 w-3.5" />
          <span className="h-1.5 w-20 rounded-full bg-muted-foreground/20" />
        </span>
      </div>

      <FileCard
        extension="PNG"
        className="start-[calc(50%-172px)] -rotate-[8deg]"
      >
        <div className="relative h-full bg-gradient-to-b from-muted-foreground/25 to-muted">
          <span className="absolute end-3 top-2 h-3 w-3 rounded-full bg-background/70" />
          <span className="absolute -bottom-3 -start-2 h-10 w-16 rotate-12 rounded-md bg-muted-foreground/30" />
          <span className="absolute -bottom-4 end-0 h-10 w-14 -rotate-12 rounded-md bg-muted-foreground/20" />
        </div>
      </FileCard>
      <FileCard
        extension="MP4"
        className="start-[calc(50%+60px)] rotate-[8deg]"
      >
        <div className="flex h-full items-center justify-center bg-gradient-to-br from-muted to-muted-foreground/25">
          <span className="flex h-6 w-6 items-center justify-center rounded-full bg-background/80 text-foreground">
            <Play className="ms-0.5 h-2.5 w-2.5 fill-current" />
          </span>
        </div>
      </FileCard>
      <FileCard
        extension="PDF"
        className="start-[calc(50%-56px)] bottom-14 z-10"
      >
        <div className="h-full space-y-1.5 bg-muted/60 p-2">
          <span className="block h-1.5 w-3/4 rounded-full bg-muted-foreground/40" />
          {['w-full', 'w-11/12', 'w-full', 'w-2/3'].map((width, index) => (
            <span
              key={index}
              className={cn(
                'block h-1 rounded-full bg-muted-foreground/20',
                width,
              )}
            />
          ))}
        </div>
      </FileCard>

      <div className="absolute -end-10 top-2 z-20 w-44 rounded-lg border border-border bg-popover p-2.5 shadow-lg">
        <div className="flex items-center gap-2">
          <ImageIcon className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
          <span className="truncate font-mono text-[10px] text-foreground">
            cover.png
          </span>
          <span className="ms-auto font-mono text-[10px] tabular-nums text-muted-foreground">
            64%
          </span>
        </div>
        <span className="mt-2 block h-1 rounded-full bg-muted">
          <span className="block h-full w-[64%] rounded-full bg-[var(--brand-cta)]" />
        </span>
      </div>

      <div className="absolute -start-12 top-10 z-20 flex items-center gap-1.5 rounded-lg border border-border bg-popover px-2.5 py-1.5 shadow-lg">
        <Wand2 className="h-3.5 w-3.5 text-[var(--brand-cta)]" />
        <span dir="ltr" className="font-mono text-[10px] text-muted-foreground">
          ?width=400&output=webp
        </span>
      </div>
    </ProductEmptyStateVisual>
  )
}

export function BucketsEmptyState({
  onCreate,
  createDisabled = false,
  createDisabledTooltip,
  footnote,
}: {
  onCreate?: () => void
  createDisabled?: boolean
  createDisabledTooltip?: string
  /** Shown under the actions (e.g. the plan limit). */
  footnote?: ReactNode
}) {
  const t = useT()
  const { features } = useConsoleProfile()
  const docsUrl = getDocsPageUrl('/docs/products/storage', features.marketing)

  return (
    <div className="mx-auto w-full max-w-4xl">
      <ProductEmptyStateHero
        as="h1"
        visual={<UploadVisual />}
        icon={HardDrive}
        title={t('Create your first bucket')}
        description={t(
          'Store images, videos, documents, and any other files. Control who can access them and serve them anywhere.',
        )}
        actions={
          <>
            <ProductEmptyStateCreateButton
              onClick={onCreate}
              disabled={createDisabled}
              disabledTooltip={createDisabledTooltip}
            >
              {t('Create bucket')}
            </ProductEmptyStateCreateButton>
            <Button variant="outline" className="h-9 text-[13px]" asChild>
              <a href={docsUrl} target="_blank" rel="noopener noreferrer">
                {t('Read the docs')}
              </a>
            </Button>
          </>
        }
      />
      {footnote ? (
        <p className="mt-4 text-center text-[12px] text-muted-foreground">
          {footnote}
        </p>
      ) : null}
      <ProductEmptyStateSteps steps={STEPS} />
    </div>
  )
}
