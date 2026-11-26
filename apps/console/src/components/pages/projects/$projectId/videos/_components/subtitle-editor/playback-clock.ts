import {
  createContext,
  useContext,
  useEffect,
  useState,
  useSyncExternalStore,
  type RefObject,
} from 'react'

/**
 * Current time of the editor's `<video>`, published every animation frame
 * while playing. Leaf components subscribe with a selector so the cue list
 * only re-renders when the value it derives changes.
 */
export class PlaybackClock {
  time = 0
  duration = 0
  playing = false
  private listeners = new Set<() => void>()

  subscribe = (listener: () => void) => {
    this.listeners.add(listener)
    return () => {
      this.listeners.delete(listener)
    }
  }

  emit() {
    for (const listener of this.listeners) listener()
  }
}

export const PlaybackClockContext = createContext<PlaybackClock | null>(null)

export function usePlaybackClockInstance(): PlaybackClock {
  const clock = useContext(PlaybackClockContext)
  if (!clock) throw new Error('PlaybackClockContext is missing')
  return clock
}

export function usePlaybackValue<T>(select: (clock: PlaybackClock) => T): T {
  const clock = usePlaybackClockInstance()
  return useSyncExternalStore(
    clock.subscribe,
    () => select(clock),
    () => select(clock),
  )
}

export function usePlaybackClock(
  videoRef: RefObject<HTMLVideoElement | null>,
): PlaybackClock {
  const [clock] = useState(() => new PlaybackClock())

  useEffect(() => {
    const el = videoRef.current
    if (!el) return
    let frame = 0
    const sync = () => {
      clock.time = el.currentTime
      clock.duration = Number.isFinite(el.duration) ? el.duration : 0
      clock.playing = !el.paused && !el.ended
      clock.emit()
    }
    const tick = () => {
      sync()
      frame = clock.playing ? requestAnimationFrame(tick) : 0
    }
    const onPlay = () => {
      cancelAnimationFrame(frame)
      frame = requestAnimationFrame(tick)
    }
    const events = [
      'timeupdate',
      'seeked',
      'seeking',
      'pause',
      'durationchange',
      'loadedmetadata',
      'ended',
    ] as const
    for (const name of events) el.addEventListener(name, sync)
    el.addEventListener('play', onPlay)
    el.addEventListener('playing', onPlay)
    sync()
    return () => {
      cancelAnimationFrame(frame)
      for (const name of events) el.removeEventListener(name, sync)
      el.removeEventListener('play', onPlay)
      el.removeEventListener('playing', onPlay)
    }
  }, [clock, videoRef])

  return clock
}
