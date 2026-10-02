import { useState } from 'react'
import { Link, useParams } from '@tanstack/react-router'
import { useQuery } from '@tanstack/react-query'
import { FileVideo, Layers, Radio } from 'lucide-react'
import { VIDEOS_PRODUCT_ICON } from '@/lib/videos/product-icon'
import { CreateVideo } from './_components/CreateVideo'
import { VideoActionButton } from './_components/VideoPage'
import { VideoTermHint } from './_components/VideoTermHint'
import { Button } from '@/components/ui/button'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { canCreateVideo } from '@/lib/console-access-checks'
import {
  useOrganizationScopes,
  useProject,
  videosSidebarQueryOptions,
} from '@/lib/react-query/hooks'
import type { VideoGlossaryTerm } from '@/lib/videos/glossary'
import { useT } from '@/lib/i18n/translate'

const STEPS: Array<{
  icon: typeof VIDEOS_PRODUCT_ICON
  title: string
  description: string
  term?: VideoGlossaryTerm
}> = [
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
    term: 'rendition',
  },
  {
    icon: Radio,
    title: 'Stream it',
    description:
      'Give the manifest URL to any HLS or DASH player. Quality adapts to each viewer.',
    term: 'manifest',
  },
]

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
    <div className="flex h-full min-h-0 flex-col overflow-y-auto">
      <div className="mx-auto w-full max-w-3xl px-6 py-12">
        <div className="flex h-11 w-11 items-center justify-center rounded-lg bg-muted text-muted-foreground">
          <VIDEOS_PRODUCT_ICON className="h-5 w-5" />
        </div>
        <h1 className="mt-4 text-[22px] font-semibold text-foreground">
          {hasVideos ? t('Select a video') : t('Stream video with Appwrite')}
        </h1>
        <p className="mt-2 max-w-xl text-[14px] text-muted-foreground">
          {hasVideos
            ? t(
                'Pick a video from the list to play it, encode renditions, add subtitles, and get streaming URLs.',
              )
            : t(
                'Turn files in Storage into adaptive streams that play smoothly on any device and connection.',
              )}
        </p>
        <div className="mt-6 flex flex-wrap items-center gap-2">
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

        <ol className="mt-10 grid gap-3 sm:grid-cols-2">
          {STEPS.map((step, index) => {
            const Icon = step.icon
            return (
              <li
                key={step.title}
                className="rounded-xl border border-border bg-card/50 p-4"
              >
                <div className="flex items-center gap-2">
                  <span className="flex h-6 w-6 items-center justify-center rounded-full bg-muted font-mono text-[11px] text-muted-foreground">
                    {index + 1}
                  </span>
                  <Icon className="h-4 w-4 text-muted-foreground" />
                  <h2 className="text-[14px] font-medium text-foreground">
                    {t(step.title)}
                  </h2>
                  {step.term ? <VideoTermHint term={step.term} /> : null}
                </div>
                <p className="mt-2 text-[13px] text-muted-foreground">
                  {t(step.description)}
                </p>
              </li>
            )
          })}
        </ol>
      </div>
      <CreateVideo
        open={createOpen}
        onOpenChange={setCreateOpen}
        projectId={projectId}
      />
    </div>
  )
}
