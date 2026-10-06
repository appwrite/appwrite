import { useParams } from '@tanstack/react-router'
import { SubtitleEditor } from '../../../_components/subtitle-editor/SubtitleEditor'
import type { SubtitleCue } from '@/lib/videos/subtitle-cues'

const NO_CUES: SubtitleCue[] = []

export function View() {
  const { projectId, videoId } = useParams({ strict: false }) as {
    projectId: string
    videoId: string
  }
  return (
    <SubtitleEditor
      projectId={projectId}
      videoId={videoId}
      initialCues={NO_CUES}
    />
  )
}
