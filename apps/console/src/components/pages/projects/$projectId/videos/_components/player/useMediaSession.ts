import { useEffect, type RefObject } from 'react'

/** How far the system's skip buttons move when they do not say. */
const SKIP_SECONDS = 10

/**
 * Hands the playing video to the operating system's media controls: media
 * keys, the lock screen and Now Playing show its title and drive it. The
 * player that last started playing owns them.
 */
export function useMediaSession(
  videoRef: RefObject<HTMLVideoElement | null>,
  title: string,
) {
  useEffect(() => {
    const video = videoRef.current
    if (!video || !('mediaSession' in navigator)) return
    const session = navigator.mediaSession

    const seekTo = (time: number) => {
      if (!Number.isFinite(video.duration)) return
      video.currentTime = Math.min(video.duration, Math.max(0, time))
    }
    const handlers: Array<[MediaSessionAction, MediaSessionActionHandler]> = [
      ['play', () => void video.play().catch(() => {})],
      ['pause', () => video.pause()],
      [
        'seekbackward',
        (details) =>
          seekTo(video.currentTime - (details.seekOffset ?? SKIP_SECONDS)),
      ],
      [
        'seekforward',
        (details) =>
          seekTo(video.currentTime + (details.seekOffset ?? SKIP_SECONDS)),
      ],
      [
        'seekto',
        (details) => {
          if (details.seekTime != null) seekTo(details.seekTime)
        },
      ],
    ]

    const updatePosition = () => {
      if (!Number.isFinite(video.duration) || video.duration <= 0) return
      try {
        session.setPositionState({
          duration: video.duration,
          playbackRate: video.playbackRate,
          position: Math.min(video.currentTime, video.duration),
        })
      } catch {
        // Older browsers have no position state.
      }
    }
    const claim = () => {
      // Visitors may not know the video's name; the page title stands in.
      session.metadata = new MediaMetadata({ title: title || document.title })
      for (const [action, handler] of handlers) {
        try {
          session.setActionHandler(action, handler)
        } catch {
          // The browser does not offer this action.
        }
      }
      updatePosition()
    }
    const syncState = () => {
      session.playbackState = video.paused ? 'paused' : 'playing'
    }

    video.addEventListener('play', claim)
    video.addEventListener('play', syncState)
    video.addEventListener('pause', syncState)
    video.addEventListener('seeked', updatePosition)
    video.addEventListener('ratechange', updatePosition)
    video.addEventListener('durationchange', updatePosition)
    if (!video.paused) claim()

    return () => {
      video.removeEventListener('play', claim)
      video.removeEventListener('play', syncState)
      video.removeEventListener('pause', syncState)
      video.removeEventListener('seeked', updatePosition)
      video.removeEventListener('ratechange', updatePosition)
      video.removeEventListener('durationchange', updatePosition)
      if (session.metadata?.title !== (title || document.title)) return
      session.metadata = null
      for (const [action] of handlers) {
        try {
          session.setActionHandler(action, null)
        } catch {
          // Nothing was set for this action.
        }
      }
    }
  }, [videoRef, title])
}
