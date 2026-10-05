/**
 * Demo videos offered in the create-video wizard.
 *
 * Open movies by Blender Studio (CC BY), served from Wikimedia Commons, which
 * sends `Access-Control-Allow-Origin: *` so the browser can download a
 * transcode and upload it into the project's Storage bucket.
 */

export type DemoVideoQuality = '1080p' | '480p'

export type DemoVideoVariant = {
  quality: DemoVideoQuality
  url: string
  /** Approximate size in bytes (decimal MB from the CDN `content-length`). */
  size: number
  width: number
  height: number
}

export type DemoVideo = {
  id: string
  title: string
  description: string
  /** Duration in milliseconds, matching `Models.Video.duration`. */
  duration: number
  posterUrl: string
  sourceUrl: string
  license: string
  variants: Record<DemoVideoQuality, DemoVideoVariant>
}

const UPLOAD = 'https://upload.wikimedia.org/wikipedia/commons/transcoded'
const THUMB = 'https://thumb.wikimedia.org/wikipedia/commons/thumb'
const COMMONS = 'https://commons.wikimedia.org/wiki/File:'

function demo(input: {
  id: string
  title: string
  description: string
  seconds: number
  path: string
  file: string
  license: string
  hd: { mb: number; width: number; height: number }
  sd: { mb: number; width: number; height: number }
}): DemoVideo {
  const base = `${UPLOAD}/${input.path}/${input.file}/${input.file}`
  return {
    id: input.id,
    title: input.title,
    description: input.description,
    duration: input.seconds * 1000,
    posterUrl: `${THUMB}/${input.path}/${input.file}/960px--${input.file}.jpg`,
    sourceUrl: `${COMMONS}${input.file}`,
    license: input.license,
    variants: {
      '1080p': {
        quality: '1080p',
        url: `${base}.1080p.vp9.webm`,
        size: Math.round(input.hd.mb * 1_000_000),
        width: input.hd.width,
        height: input.hd.height,
      },
      '480p': {
        quality: '480p',
        url: `${base}.480p.vp9.webm`,
        size: Math.round(input.sd.mb * 1_000_000),
        width: input.sd.width,
        height: input.sd.height,
      },
    },
  }
}

export const DEMO_VIDEOS: DemoVideo[] = [
  demo({
    id: 'daily-dweebs',
    title: 'The Daily Dweebs',
    description: 'Short and light. The fastest way to try encoding.',
    seconds: 60,
    path: 'b/b2',
    file: 'The_Daily_Dweebs_-_Blender_Open_Movie-full_movie.webm',
    license: 'CC BY 4.0',
    hd: { mb: 9.4, width: 1920, height: 1080 },
    sd: { mb: 3.5, width: 854, height: 480 },
  }),
  demo({
    id: 'llama-drama',
    title: 'Caminandes: Llama Drama',
    description: 'Bright 16:9 cartoon with fast camera moves.',
    seconds: 90,
    path: 'd/d0',
    file: 'Caminandes-_Llama_Drama_-_Short_Movie.ogv',
    license: 'CC BY 3.0',
    hd: { mb: 16.2, width: 1920, height: 1080 },
    sd: { mb: 4.7, width: 854, height: 480 },
  }),
  demo({
    id: 'gran-dillama',
    title: 'Caminandes: Gran Dillama',
    description: 'Longer 16:9 short with detailed outdoor scenes.',
    seconds: 146,
    path: '7/7c',
    file: 'Caminandes_-_Gran_Dillama_-_Blender_Foundation%27s_new_Open_Movie.webm',
    license: 'CC BY 3.0',
    hd: { mb: 52.2, width: 1920, height: 1080 },
    sd: { mb: 16.1, width: 854, height: 480 },
  }),
  demo({
    id: 'coffee-run',
    title: 'Coffee Run',
    description: 'Ultrawide frame. Shows letterboxed renditions.',
    seconds: 185,
    path: '3/3f',
    file: 'Coffee_Run_-_Blender_Open_Movie-full_movie.webm',
    license: 'CC BY 4.0',
    hd: { mb: 37.7, width: 1920, height: 804 },
    sd: { mb: 14.8, width: 854, height: 358 },
  }),
  demo({
    id: 'wing-it',
    title: 'Wing It!',
    description: 'Fast action and rich color for bitrate tests.',
    seconds: 238,
    path: '3/38',
    file: 'WING_IT%21_-_Blender_Open_Movie-full_movie.webm',
    license: 'CC BY 4.0',
    hd: { mb: 53.5, width: 1920, height: 1080 },
    sd: { mb: 19.6, width: 854, height: 480 },
  }),
  demo({
    id: 'hero',
    title: 'Hero',
    description: 'Ultrawide hybrid of 2D and 3D animation.',
    seconds: 237,
    path: 'a/a9',
    file: 'HERO_-_Blender_Open_Movie-full_movie.webm',
    license: 'CC BY 4.0',
    hd: { mb: 42.8, width: 1920, height: 804 },
    sd: { mb: 16.5, width: 854, height: 358 },
  }),
]

/**
 * Local high-quality 1080p encode of Pixabay "Water, Falls, Waterfall" for the
 * Videos marketing hero. Pixabay Content License (McPix22). Downscaled from
 * the 4K source at CRF 16, capped at 20 Mbps, so the hero player is above
 * the bitrate the frame needs.
 */
export const VIDEOS_HERO_DEMO = {
  id: 'waterfall',
  src: '/videos/demo/waterfall-1080p.mp4?v=hq',
  poster: '/videos/demo/waterfall-poster.jpg',
  fileName: 'falls.mp4',
  sizeLabel: '85 MB',
  startAt: 0,
  title: 'Waterfall',
  license: 'Pixabay Content License',
  licenseHref: 'https://pixabay.com/service/license-summary/',
  sourceHref: 'https://pixabay.com/videos/water-falls-waterfall-358693/',
  attribution: 'McPix22',
} as const

/** Sizes are approximate, so leave headroom against the bucket limit. */
const SIZE_HEADROOM = 1.05

/**
 * Best quality that fits the bucket's maximum file size, or `null` when even
 * the smallest transcode is too large.
 */
export function pickDemoVideoVariant(
  video: DemoVideo,
  maxFileSize: number | null | undefined,
): DemoVideoVariant | null {
  const fits = (variant: DemoVideoVariant) =>
    !maxFileSize || variant.size * SIZE_HEADROOM <= maxFileSize
  if (fits(video.variants['1080p'])) return video.variants['1080p']
  if (fits(video.variants['480p'])) return video.variants['480p']
  return null
}

export function demoVideoFileName(
  video: DemoVideo,
  variant: DemoVideoVariant,
): string {
  return `${video.id}-${variant.quality}.webm`
}

/** Download a demo transcode into memory, reporting progress from 0 to 100. */
export async function downloadDemoVideo(
  video: DemoVideo,
  variant: DemoVideoVariant,
  onProgress: (progress: number) => void,
): Promise<File> {
  const response = await fetch(variant.url)
  if (!response.ok || !response.body) {
    throw new Error(`Demo download failed (HTTP ${response.status})`)
  }
  const total = Number(response.headers.get('content-length')) || variant.size
  const reader = response.body.getReader()
  const chunks: BlobPart[] = []
  let received = 0
  for (;;) {
    const { done, value } = await reader.read()
    if (done) break
    chunks.push(value)
    received += value.byteLength
    onProgress(Math.min(100, (received / total) * 100))
  }
  onProgress(100)
  return new File(chunks, demoVideoFileName(video, variant), {
    type: 'video/webm',
  })
}
