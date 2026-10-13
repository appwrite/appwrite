import { useMemo } from 'react'
import { useSearch } from '@tanstack/react-router'
import { AlertCircle, Loader2 } from 'lucide-react'
import type { Models } from '@appwrite.io/console'
import { EmbedUnauthorized } from '@/components/pages/player/EmbedUnauthorized'
import { VideoStreamPlayer } from '@/components/pages/projects/$projectId/videos/_components/player/VideoStreamPlayer'
import { VideoInspectorProvider } from '@/components/pages/projects/$projectId/videos/_components/player/VideoInspectorContext'
import {
  getVideoEncodingStatus,
  useProjectVideo,
  useVideoRenditions,
} from '@/lib/react-query/hooks/videos'
import {
  VIDEO_EMBED_MISSING_PROJECT,
  VIDEO_EMBED_NOT_READY,
  videoEmbedErrorFromUnknown,
  type VideoEmbedError,
} from '@/lib/videos/embed-player-errors'
import { parseEmbedStartParam } from '@/lib/videos/embed-start-time'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

type EmbedViewProps = {
  videoId: string
  initialData?: {
    video: Models.Video
    renditions: Models.VideoRendition[]
  }
}

function EmbedErrorScreen({ error }: { error: VideoEmbedError }) {
  const t = useT()
  return (
    <div className="flex h-[100dvh] w-full items-center justify-center bg-black px-6">
      <div className="max-w-md text-center">
        <span className="mx-auto flex size-12 items-center justify-center rounded-xl border border-white/10 bg-white/5 text-white/80">
          <AlertCircle className="size-6" />
        </span>
        <h1 className="mt-4 text-[17px] font-semibold text-white">
          {t(error.title)}
        </h1>
        <p className="mt-2 text-[14px] leading-relaxed text-white/70">
          {t(error.message)}
        </p>
      </div>
    </div>
  )
}

export function View({ videoId, initialData }: EmbedViewProps) {
  const t = useT()
  const search = useSearch({ strict: false }) as {
    project?: string
    start?: number | string
  }
  const projectId = search.project?.trim() ?? ''
  const initialStartSeconds = parseEmbedStartParam(search.start)

  const {
    data: videoFromHook,
    error: videoError,
    isLoading: videoLoading,
    isFetched: videoFetched,
  } = useProjectVideo(projectId || null, videoId)
  const {
    data: renditionsFromHook,
    error: renditionsError,
    isLoading: renditionsLoading,
    isFetched: renditionsFetched,
  } = useVideoRenditions(projectId || null, videoId)

  const video = videoFromHook ?? initialData?.video
  const renditions =
    renditionsFromHook?.renditions ?? initialData?.renditions ?? []

  const loadError = useMemo(() => {
    if (!projectId) return VIDEO_EMBED_MISSING_PROJECT
    const err = videoError ?? renditionsError
    if (err) return videoEmbedErrorFromUnknown(err)
    return null
  }, [projectId, videoError, renditionsError])

  if (!projectId) {
    return <EmbedErrorScreen error={VIDEO_EMBED_MISSING_PROJECT} />
  }

  const stillLoading =
    (!video && videoLoading) || (!renditionsFromHook && renditionsLoading)

  if (stillLoading && !initialData?.video) {
    return (
      <div
        className="flex h-[100dvh] w-full items-center justify-center bg-black"
        aria-busy
        aria-label={t('Loading video')}
      >
        <Loader2 className="size-8 animate-spin text-white/60" />
      </div>
    )
  }

  if (loadError && videoFetched && renditionsFetched) {
    if (loadError.kind === 'unauthorized') return <EmbedUnauthorized />
    return <EmbedErrorScreen error={loadError} />
  }

  if (!video) {
    return (
      <EmbedErrorScreen
        error={videoEmbedErrorFromUnknown(videoError ?? new Error('not found'))}
      />
    )
  }

  const encodingStatus = getVideoEncodingStatus(renditions)
  const canTryPlayback =
    encodingStatus === 'ready' ||
    encodingStatus === 'encoding' ||
    (video.duration ?? 0) > 0

  if (!canTryPlayback) {
    return <EmbedErrorScreen error={VIDEO_EMBED_NOT_READY} />
  }

  return (
    <VideoInspectorProvider>
      <div className={cn('h-[100dvh] w-full overflow-hidden bg-black')}>
        <VideoStreamPlayer
          projectId={projectId}
          video={video}
          renditions={renditions}
          layout="embed"
          initialStartSeconds={initialStartSeconds}
        />
      </div>
    </VideoInspectorProvider>
  )
}
