import { useParams } from '@tanstack/react-router'
import type { Models } from '@appwrite.io/console'
import { Layers } from 'lucide-react'
import { useVideoDetailActions } from '../Layout'
import { VideoStreamPlayer } from '../_components/player/VideoStreamPlayer'
import { Button } from '@/components/ui/button'
import {
  useProjectVideo,
  useVideoRenditions,
  useVideoSubtitles,
} from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'

export type VideoOverviewInitialData = {
  video: Models.Video
  renditions: Models.VideoRenditionList
  subtitles: Models.VideoSubtitleList
}

type ViewProps = {
  initialData?: VideoOverviewInitialData
}

export function View({ initialData }: ViewProps = {}) {
  const t = useT()
  const { projectId, videoId } = useParams({ strict: false }) as {
    projectId: string
    videoId: string
  }
  const { openCreateRenditions, canWrite } = useVideoDetailActions()

  const { data: videoFromHook } = useProjectVideo(projectId, videoId)
  const { data: renditionsFromHook } = useVideoRenditions(projectId, videoId)
  const { data: subtitlesFromHook } = useVideoSubtitles(projectId, videoId)

  const video = videoFromHook ?? initialData?.video
  const renditions =
    renditionsFromHook?.renditions ?? initialData?.renditions.renditions ?? []
  const subtitles =
    subtitlesFromHook?.subtitles ?? initialData?.subtitles.subtitles ?? []

  if (!video) return null

  const showRenditionsCta = renditions.length === 0

  return (
    <div className="mx-auto w-full max-w-7xl space-y-4 px-4 pt-4 pb-6 sm:px-6 sm:pt-6">
      {showRenditionsCta ? (
        <div className="flex flex-col gap-3 rounded-xl border border-border bg-card/50 px-6 py-4 sm:flex-row sm:items-center sm:justify-between">
          <div className="flex min-w-0 items-start gap-3">
            <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground">
              <Layers className="h-4 w-4" />
            </div>
            <div className="min-w-0">
              <h3 className="text-[15px] font-semibold text-foreground">
                {t('Create your first renditions')}
              </h3>
              <p className="mt-1 text-[13px] text-muted-foreground">
                {t(
                  'Encode this video into HLS, DASH, or CMAF for adaptive streaming. Until then, the player uses the original Storage file.',
                )}
              </p>
            </div>
          </div>
          <Button
            size="sm"
            className="h-9 shrink-0 text-[13px]"
            onClick={openCreateRenditions}
            disabled={!canWrite}
          >
            {t('Create renditions')}
          </Button>
        </div>
      ) : null}

      <VideoStreamPlayer
        projectId={projectId}
        video={video}
        renditions={renditions}
        subtitles={subtitles}
        canWrite={canWrite}
      />
    </div>
  )
}
