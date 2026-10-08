import { useState } from 'react'
import { Link, useParams } from '@tanstack/react-router'
import { useQuery } from '@tanstack/react-query'
import {
  Captions,
  Check,
  FileVideo,
  Layers,
  Maximize,
  Play,
  Radio,
  Settings,
  Volume2,
} from 'lucide-react'
import { VIDEOS_PRODUCT_ICON } from '@/lib/videos/product-icon'
import { CreateVideo } from './_components/CreateVideo'
import { VideoActionButton } from './_components/VideoPage'
import { VideoTermHint } from './_components/VideoTermHint'
import {
  ProductEmptyStateSteps,
  ProductEmptyStateVisual,
  type ProductEmptyStateStep,
} from '@/components/global/shared/ProductEmptyState'
import { Button } from '@/components/ui/button'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { canCreateVideo } from '@/lib/console-access-checks'
import {
  useOrganizationScopes,
  useProject,
  videosSidebarQueryOptions,
} from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const STEPS: ProductEmptyStateStep[] = [
  {
    icon: FileVideo,
    title: 'Create a video',
    description:
      'Pick a video or audio file you uploaded to Storage. The file stays in its bucket.',
  },
  {
    icon: Layers,
    title: 'Encode renditions',
    description:
      'Choose encoding profiles and an output format. Each profile becomes one quality level, and Appwrite reads the file metadata on the first job.',
    hint: <VideoTermHint term="rendition" />,
  },
  {
    icon: Radio,
    title: 'Stream it',
    description:
      'Give the manifest URL to any HLS or DASH player. Quality adapts to each viewer.',
    hint: <VideoTermHint term="manifest" />,
  },
]

const RENDITIONS = [
  { label: '1080p', bitrate: '5.0 Mbps', active: true },
  { label: '720p', bitrate: '2.8 Mbps', active: false },
  { label: '480p', bitrate: '1.2 Mbps', active: false },
  { label: '360p', bitrate: '0.6 Mbps', active: false },
]

/** Decorative adaptive player with a rendition menu and subtitle tracks. */
function PlayerVisual() {
  return (
    <ProductEmptyStateVisual className="px-6 py-8">
      <div className="overflow-hidden rounded-xl border border-border bg-card shadow-xl">
        <div className="relative aspect-video overflow-hidden bg-gradient-to-br from-muted via-muted/70 to-muted-foreground/25">
          <div className="absolute -bottom-1/3 -start-1/4 h-full w-3/4 rounded-full bg-muted-foreground/10 blur-2xl" />
          <div className="absolute -top-1/4 end-0 h-2/3 w-1/2 rounded-full bg-background/40 blur-2xl" />

          <span className="absolute start-3 top-3 rounded-md bg-background/70 px-1.5 font-mono text-[10px] leading-5 text-foreground/80 backdrop-blur-sm">
            HLS
          </span>

          <span className="absolute inset-0 flex items-center justify-center">
            <span className="flex h-14 w-14 items-center justify-center rounded-full bg-[var(--brand-cta)] text-[var(--brand-cta-foreground)] shadow-lg ring-8 ring-[color-mix(in_oklch,var(--brand-cta)_20%,transparent)]">
              <Play className="ms-1 h-5 w-5 fill-current" />
            </span>
          </span>

          <span className="absolute inset-x-0 bottom-14 flex flex-col items-center gap-1">
            <span className="h-2 w-40 rounded-sm bg-foreground/60" />
            <span className="h-2 w-28 rounded-sm bg-foreground/60" />
          </span>

          <div className="absolute inset-x-0 bottom-0 bg-gradient-to-t from-background/80 to-transparent px-3 pb-2.5 pt-6">
            <div className="relative h-1 rounded-full bg-foreground/15">
              <span className="absolute inset-y-0 start-0 w-3/5 rounded-full bg-foreground/20" />
              <span className="absolute inset-y-0 start-0 w-[38%] rounded-full bg-[var(--brand-cta)]" />
              <span className="absolute top-1/2 start-[38%] h-2.5 w-2.5 -translate-x-1/2 -translate-y-1/2 rounded-full bg-[var(--brand-cta)] shadow rtl:translate-x-1/2" />
            </div>
            <div className="mt-2 flex items-center gap-3 text-foreground/80">
              <Play className="h-3.5 w-3.5 fill-current" />
              <Volume2 className="h-3.5 w-3.5" />
              <span className="font-mono text-[10px] tabular-nums">
                02:14 / 05:48
              </span>
              <Captions className="ms-auto h-3.5 w-3.5" />
              <Settings className="h-3.5 w-3.5 text-foreground" />
              <Maximize className="h-3.5 w-3.5" />
            </div>
          </div>
        </div>
      </div>

      <div className="absolute bottom-0 end-0 w-44 rounded-lg border border-border bg-popover p-1 shadow-lg">
        {RENDITIONS.map((rendition) => (
          <div
            key={rendition.label}
            className={cn(
              'flex items-center gap-2 rounded-md px-2 py-1.5 font-mono text-[11px]',
              rendition.active
                ? 'bg-muted text-foreground'
                : 'text-muted-foreground',
            )}
          >
            <Check
              className={cn(
                'h-3 w-3 shrink-0',
                rendition.active ? 'text-[var(--brand-cta)]' : 'invisible',
              )}
            />
            <span>{rendition.label}</span>
            <span className="ms-auto text-[10px] tabular-nums text-muted-foreground">
              {rendition.bitrate}
            </span>
          </div>
        ))}
      </div>

      <div className="absolute start-0 top-0 flex items-center gap-1.5 rounded-lg border border-border bg-popover px-2.5 py-1.5 shadow-lg">
        <Captions className="h-3.5 w-3.5 text-muted-foreground" />
        {['en', 'es', 'ja'].map((language, index) => (
          <span
            key={language}
            className={cn(
              'rounded px-1 font-mono text-[10px] leading-4',
              index === 0
                ? 'bg-foreground text-background'
                : 'text-muted-foreground',
            )}
          >
            {language}
          </span>
        ))}
      </div>
    </ProductEmptyStateVisual>
  )
}

/** Shown when the project has no videos (desktop lands on the first video otherwise). */
export function View() {
  const t = useT()
  const { projectId } = useParams({ strict: false }) as { projectId: string }
  const [createOpen, setCreateOpen] = useState(false)
  const { data } = useQuery(videosSidebarQueryOptions(projectId))
  const { project } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)
  const noCreatePermission = !canCreateVideo(access, features)
  const hasVideos = (data?.total ?? 0) > 0

  return (
    <div className="@container flex h-full min-h-0 flex-col overflow-y-auto">
      <div className="mx-auto my-auto w-full max-w-6xl px-6 py-12 @5xl:px-10 sm:py-16">
        <div className="grid items-center gap-12 @3xl:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)] @3xl:gap-10 @5xl:gap-14">
          <div className="text-start">
            <span className="flex h-11 w-11 items-center justify-center rounded-lg border border-border bg-muted text-muted-foreground">
              <VIDEOS_PRODUCT_ICON className="h-5 w-5" />
            </span>
            <h1 className="mt-6 text-[28px] font-semibold leading-tight tracking-tight text-foreground">
              {hasVideos
                ? t('Select a video')
                : t('Stream video with Appwrite')}
            </h1>
            <p className="mt-3 max-w-md text-[14px] leading-relaxed text-muted-foreground">
              {hasVideos
                ? t(
                    'Pick a video from the list to play it, encode renditions, add subtitles, and get streaming URLs.',
                  )
                : t(
                    'Turn files in Storage into adaptive streams that play smoothly on any device and connection.',
                  )}
            </p>
            <div className="mt-7 flex flex-wrap items-center gap-2">
              <VideoActionButton
                variant="brandCta"
                className="h-9 text-[13px]"
                onClick={() => setCreateOpen(true)}
                disabledReason={
                  noCreatePermission
                    ? t("You don't have permission to create videos.")
                    : null
                }
              >
                {t('Create video')}
              </VideoActionButton>
              <Button variant="outline" className="h-9 text-[13px]" asChild>
                <Link
                  to="/projects/$projectId/videos/profiles"
                  params={{ projectId }}
                >
                  {t('Encoding profiles')}
                </Link>
              </Button>
            </div>
          </div>

          <div className="order-first w-full max-w-md @3xl:order-none @3xl:max-w-none">
            <PlayerVisual />
          </div>
        </div>
        <ProductEmptyStateSteps steps={STEPS} />
      </div>
      <CreateVideo
        open={createOpen}
        onOpenChange={setCreateOpen}
        projectId={projectId}
      />
    </div>
  )
}
