import { Loader2 } from 'lucide-react'
import { useLongWait } from '@/hooks/use-long-wait'
import { VideoStreamPlayer } from '@/components/pages/projects/$projectId/videos/_components/player/VideoStreamPlayer'
import type { LaunchEventDayFilm } from '@/lib/init/types'
import { usePublicHlsPlayback } from '@/lib/react-query/hooks/videos'

/**
 * An Init launch film in the Appwrite player, right in the page: streamed from
 * its public HLS playlist as any visitor would, and playing as soon as it can.
 * Loaded on demand, so the Init page carries none of the player until asked.
 */
export default function InitFilmPlayer({ film }: { film: LaunchEventDayFilm }) {
  const { data, isLoading } = usePublicHlsPlayback(film.projectId, film.videoId)
  const loadingLong = useLongWait(isLoading)

  if (!data) {
    return (
      <div className="flex size-full items-center justify-center bg-black text-[13px] text-white/60">
        {isLoading ? (
          loadingLong ? (
            <Loader2
              className="size-7 animate-spin"
              aria-label="Loading film"
            />
          ) : null
        ) : (
          <p>This film isn&apos;t ready to play yet.</p>
        )}
      </div>
    )
  }

  return (
    <VideoStreamPlayer
      key={film.videoId}
      projectId={film.projectId}
      video={{ ...data.video, name: film.label }}
      renditions={data.renditions}
      layout="embed"
      autoPlay
    />
  )
}
