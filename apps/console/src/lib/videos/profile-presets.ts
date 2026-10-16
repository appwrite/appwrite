/** Encoding presets offered when creating a video profile. Bitrates in kbps. */

export type ProfileSpec = {
  width: number
  height: number
  videoBitRate: number
  audioBitRate: number
}

export type ProfilePreset = ProfileSpec & { name: string }

export const PROFILE_PRESETS: ProfilePreset[] = [
  {
    name: '360p',
    width: 640,
    height: 360,
    videoBitRate: 800,
    audioBitRate: 96,
  },
  {
    name: '480p',
    width: 854,
    height: 480,
    videoBitRate: 1400,
    audioBitRate: 128,
  },
  {
    name: '720p',
    width: 1280,
    height: 720,
    videoBitRate: 2800,
    audioBitRate: 128,
  },
  {
    name: '1080p',
    width: 1920,
    height: 1080,
    videoBitRate: 5000,
    audioBitRate: 192,
  },
  {
    name: '1440p',
    width: 2560,
    height: 1440,
    videoBitRate: 8000,
    audioBitRate: 192,
  },
  {
    name: '2160p',
    width: 3840,
    height: 2160,
    videoBitRate: 16000,
    audioBitRate: 192,
  },
]

export const DEFAULT_PROFILE_PRESET = PROFILE_PRESETS[2]

export function isSameSpec(a: ProfileSpec, b: ProfileSpec): boolean {
  return (
    a.width === b.width &&
    a.height === b.height &&
    a.videoBitRate === b.videoBitRate &&
    a.audioBitRate === b.audioBitRate
  )
}

export function isPresetSpec(spec: ProfileSpec): boolean {
  return PROFILE_PRESETS.some((preset) => isSameSpec(preset, spec))
}
