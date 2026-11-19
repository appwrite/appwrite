import { useEffect, useState, type RefObject } from 'react'
import { Pause, Play, Volume2, VolumeX } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Slider } from '@/components/ui/slider'
import { useT } from '@/lib/i18n/translate'
import { formatPlayerClock } from '@/lib/utils/video-format'

/**
 * Slim controls beside the docked player: play, time, seek and mute. The full
 * overlay controls do not fit a strip this short.
 */
export function DockedPlayerControls({
  videoRef,
  title,
}: {
  videoRef: RefObject<HTMLVideoElement | null>
  title: string
}) {
  const t = useT()
  const [playing, setPlaying] = useState(false)
  const [muted, setMuted] = useState(false)
  const [time, setTime] = useState(0)
  const [duration, setDuration] = useState(0)

  useEffect(() => {
    const video = videoRef.current
    if (!video) return
    const sync = () => {
      setPlaying(!video.paused && !video.ended)
      setMuted(video.muted || video.volume === 0)
      setTime(video.currentTime)
      setDuration(Number.isFinite(video.duration) ? video.duration : 0)
    }
    sync()
    const events = [
      'play',
      'pause',
      'ended',
      'timeupdate',
      'durationchange',
      'volumechange',
      'loadedmetadata',
    ] as const
    events.forEach((name) => video.addEventListener(name, sync))
    return () => events.forEach((name) => video.removeEventListener(name, sync))
  }, [videoRef])

  const video = videoRef.current
  const togglePlay = () => {
    if (!video) return
    if (video.paused) void video.play()
    else video.pause()
  }

  return (
    <div className="flex min-w-0 flex-1 items-center gap-3">
      <Button
        type="button"
        variant="outline"
        size="icon"
        className="size-9 shrink-0 rounded-full"
        onClick={togglePlay}
        aria-label={playing ? t('Pause') : t('Play')}
      >
        {playing ? (
          <Pause className="size-4 fill-current" />
        ) : (
          <Play className="size-4 translate-x-px fill-current" />
        )}
      </Button>
      <div className="flex min-w-0 flex-1 flex-col gap-2">
        <div className="flex min-w-0 items-baseline justify-between gap-3">
          <span className="truncate text-[13px] font-medium text-foreground">
            {title}
          </span>
          <span className="shrink-0 font-mono text-[12px] tabular-nums text-muted-foreground">
            {formatPlayerClock(time)} / {formatPlayerClock(duration)}
          </span>
        </div>
        <Slider
          aria-label={t('Seek')}
          min={0}
          max={duration || 1}
          step={0.1}
          value={[Math.min(time, duration || 1)]}
          disabled={!duration}
          onValueChange={([next]) => {
            if (video && next != null) video.currentTime = next
          }}
        />
      </div>
      <Button
        type="button"
        variant="ghost"
        size="icon"
        className="size-9 shrink-0 text-muted-foreground"
        onClick={() => {
          if (!video) return
          video.muted = !muted
          if (!video.muted && video.volume === 0) video.volume = 1
        }}
        aria-label={muted ? t('Unmute') : t('Mute')}
      >
        {muted ? (
          <VolumeX className="size-4" />
        ) : (
          <Volume2 className="size-4" />
        )}
      </Button>
    </div>
  )
}
