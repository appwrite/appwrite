/**
 * When a `<video>` first has a picture to show, on the page's monotonic clock
 * (`performance.now()`), the clock players time their requests with.
 *
 * `loadeddata` says so once the main thread gets to it, so a busy page hears
 * it late. `requestVideoFrameCallback` reports when the frame reached the
 * compositor, whatever the main thread was doing. The earlier of the two
 * counts: a browser that presents the first frame only once playback starts
 * reports it later, and waiting for the viewer to press play is not startup.
 *
 * Calls `onFrame` with each earlier time it learns of, and returns a function
 * that stops watching.
 */
export function watchFirstFrame(
  video: HTMLVideoElement,
  onFrame: (at: number) => void,
): () => void {
  // A frame presented before this point is the previous source's.
  const since = performance.now()
  let first = Number.POSITIVE_INFINITY
  const report = (at: number) => {
    if (at >= first) return
    first = at
    onFrame(at)
  }

  const onLoadedData = (event: Event) => report(eventTime(event))
  video.addEventListener('loadeddata', onLoadedData)

  let request: number | null = null
  const awaitFrame = () => {
    request = video.requestVideoFrameCallback((_now, frame) => {
      request = null
      const at = frame.presentationTime
      if (at < since) {
        // Once `loadeddata` has come, a later frame cannot be the first.
        if (first === Number.POSITIVE_INFINITY) awaitFrame()
        return
      }
      if (at <= performance.now()) report(at)
    })
  }
  if (typeof video.requestVideoFrameCallback === 'function') awaitFrame()

  return () => {
    video.removeEventListener('loadeddata', onLoadedData)
    if (request != null) video.cancelVideoFrameCallback(request)
  }
}

/** When the browser fired `event`, on the same clock; now when it cannot say. */
export function eventTime(event: Event): number {
  const now = performance.now()
  return event.timeStamp > 0 && event.timeStamp <= now ? event.timeStamp : now
}
