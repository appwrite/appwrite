import { useState } from 'react'
import { Link, useParams } from '@tanstack/react-router'
import type { Models } from '@appwrite.io/console'
import { Captions } from 'lucide-react'
import { SubtitleEditor } from '../../../_components/subtitle-editor/SubtitleEditor'
import { VideoPage } from '../../../_components/VideoPage'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { Button } from '@/components/ui/button'
import { useT } from '@/lib/i18n/translate'
import {
  useVideoCaptionCues,
  useVideoCaptions,
} from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import type { SubtitleCue } from '@/lib/videos/subtitle-cues'

type ViewProps = {
  initialData?: {
    caption: Models.VideoCaption | null
    cues: SubtitleCue[] | null
  }
}

const NO_CUES: SubtitleCue[] = []

export function View({ initialData }: ViewProps = {}) {
  const t = useT()
  const { projectId, videoId, captionId } = useParams({
    strict: false,
  }) as { projectId: string; videoId: string; captionId: string }
  const { data: captionsData, isLoading: captionsLoading } =
    useVideoCaptions(projectId, videoId)
  const caption =
    captionsData?.captions.find((item) => item.$id === captionId) ??
    initialData?.caption ??
    null

  // Saving points the track at a new file; keep reading the file the editor opened with.
  const [loadTarget] = useState(caption)
  const cuesQuery = useVideoCaptionCues(projectId, videoId, loadTarget)
  const [startCues, setStartCues] = useState<SubtitleCue[] | null>(
    () => initialData?.cues ?? null,
  )
  const initialCues = startCues ?? cuesQuery.data ?? null

  const backAction = (
    <Button asChild variant="outline" size="sm" className="h-9 text-[13px]">
      <Link
        to="/projects/$projectId/videos/$videoId/captions"
        params={{ projectId, videoId }}
      >
        {t('Back to captions')}
      </Link>
    </Button>
  )

  if (!caption) {
    if (captionsLoading) return null
    return (
      <VideoPage title={t('Caption editor')}>
        <EmptyState
          icon={Captions}
          title={t('Caption not found')}
          description={t(
            'This caption may have been deleted. Go back to the list to pick another track.',
          )}
          isEmpty
          hasFilters={false}
          variant="card"
          action={backAction}
        />
      </VideoPage>
    )
  }

  if (!initialCues) {
    if (cuesQuery.isLoading || cuesQuery.isFetching) {
      return (
        <div className="flex h-full items-center justify-center">
          <p className="text-[13px] text-muted-foreground">
            {t('Loading cues...')}
          </p>
        </div>
      )
    }
    return (
      <VideoPage title={caption.name}>
        <EmptyState
          icon={Captions}
          title={t("Couldn't load this track")}
          description={`${
            caption.embedded && caption.status !== 'ready'
              ? t('Embedded tracks can be read once they are packaged.')
              : t('The caption file could not be read.')
          } ${getErrorMessage(cuesQuery.error) ?? ''}`.trim()}
          isEmpty
          hasFilters={false}
          variant="card"
          action={
            <div className="flex flex-wrap justify-center gap-2">
              <Button
                variant="outline"
                size="sm"
                className="h-9 text-[13px]"
                onClick={() => setStartCues(NO_CUES)}
              >
                {t('Start from an empty track')}
              </Button>
              <Button
                size="sm"
                className="h-9 text-[13px]"
                onClick={() => void cuesQuery.refetch()}
              >
                {t('Retry')}
              </Button>
            </div>
          }
        />
      </VideoPage>
    )
  }

  return (
    <SubtitleEditor
      projectId={projectId}
      videoId={videoId}
      subtitle={caption}
      initialCues={initialCues}
    />
  )
}
