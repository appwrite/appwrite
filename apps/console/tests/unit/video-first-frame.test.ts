import { describe, expect, test } from 'bun:test'
import { eventTime, watchFirstFrame } from '@/lib/videos/first-frame'

type FrameCallback = (now: number, frame: { presentationTime: number }) => void

/** A `<video>` that presents a frame when the test says so. */
class FakeVideo extends EventTarget {
  private callbacks = new Map<number, FrameCallback>()
  private lastId = 0

  requestVideoFrameCallback(callback: FrameCallback): number {
    this.lastId += 1
    this.callbacks.set(this.lastId, callback)
    return this.lastId
  }

  cancelVideoFrameCallback(id: number): void {
    this.callbacks.delete(id)
  }

  /** Runs the waiting callbacks for a frame presented at `presentationTime`. */
  present(presentationTime: number): void {
    const waiting = [...this.callbacks.values()]
    this.callbacks.clear()
    for (const callback of waiting) {
      callback(performance.now(), { presentationTime })
    }
  }

  get waiting(): number {
    return this.callbacks.size
  }
}

/** A browser without `requestVideoFrameCallback`. */
class OlderVideo extends EventTarget {}

function loadedData(timeStamp: number): Event {
  const event = new Event('loadeddata')
  Object.defineProperty(event, 'timeStamp', { value: timeStamp })
  return event
}

function watch(video: EventTarget) {
  const frames: number[] = []
  const stop = watchFirstFrame(video as unknown as HTMLVideoElement, (at) =>
    frames.push(at),
  )
  return { frames, stop }
}

describe('watchFirstFrame', () => {
  test('takes the frame the compositor got before loadeddata reached the page', () => {
    const video = new FakeVideo()
    const { frames, stop } = watch(video)
    const presented = performance.now()
    video.dispatchEvent(loadedData(performance.now()))
    video.present(presented)
    expect(frames.at(-1)).toBe(presented)
    stop()
  })

  test('keeps loadeddata when the frame shows only once playback starts', () => {
    const video = new FakeVideo()
    const { frames, stop } = watch(video)
    const announced = performance.now()
    video.dispatchEvent(loadedData(announced))
    video.present(performance.now())
    expect(frames).toEqual([announced])
    stop()
  })

  test('skips a frame from the previous source and waits for the next', () => {
    const video = new FakeVideo()
    const before = performance.now()
    const { frames, stop } = watch(video)
    video.present(before - 1)
    expect(frames).toEqual([])
    expect(video.waiting).toBe(1)
    const presented = performance.now()
    video.present(presented)
    expect(frames).toEqual([presented])
    stop()
  })

  test('uses loadeddata where the browser cannot report frames', () => {
    const video = new OlderVideo()
    const { frames, stop } = watch(video)
    const announced = performance.now()
    video.dispatchEvent(loadedData(announced))
    expect(frames).toEqual([announced])
    stop()
  })

  test('stops listening once cleaned up', () => {
    const video = new FakeVideo()
    const { frames, stop } = watch(video)
    stop()
    expect(video.waiting).toBe(0)
    video.dispatchEvent(loadedData(performance.now()))
    expect(frames).toEqual([])
  })
})

describe('eventTime', () => {
  test("reads the event's time, or now when it carries none", () => {
    const at = performance.now()
    expect(eventTime(loadedData(at))).toBe(at)
    const before = performance.now()
    expect(eventTime(loadedData(0))).toBeGreaterThanOrEqual(before)
  })
})
