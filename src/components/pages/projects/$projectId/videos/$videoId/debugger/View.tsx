import { useParams } from '@tanstack/react-router'
import { VideoPage } from '../../_components/VideoPage'
import { VideoStreamPlayer } from '../../_components/player/VideoStreamPlayer'
import { useProjectVideo, useVideoRenditions } from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'

export function View() {
  const t = useT()
  const { projectId, videoId } = useParams({ strict: false }) as {
    projectId: string
    videoId: string
  }
  const { data: video } = useProjectVideo(projectId, videoId)
  const { data: renditionsData } = useVideoRenditions(projectId, videoId)

  if (!video) return null

  return (
    <VideoPage title={t('Debugger')} term="adaptive">
      <VideoStreamPlayer
        projectId={projectId}
        video={video}
        renditions={renditionsData?.renditions ?? []}
        showProcessing={false}
      />
    </VideoPage>
  )
}
