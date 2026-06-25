import { useEffect } from 'react'
import { prefetch, staticFile } from 'remotion'
import { CONSOLE_SCREENSHOT_VIDEO_SRC, EXPLORER_VIDEO_SRC, FEATURE_CLIPS } from '../constants'

/** Warm the video cache before feature scenes play. */
export function VideoPrefetch() {
  useEffect(() => {
    const sources = new Set<string>([
      CONSOLE_SCREENSHOT_VIDEO_SRC,
      EXPLORER_VIDEO_SRC,
      ...FEATURE_CLIPS.flatMap((clip) =>
        clip.videoSrc ? [clip.videoSrc] : [],
      ),
    ])

    const handles = [...sources].map((src) => prefetch(staticFile(src)))

    return () => {
      for (const handle of handles) {
        handle.free()
      }
    }
  }, [])

  return null
}
