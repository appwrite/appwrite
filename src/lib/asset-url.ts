import { getRuntimeConfig } from './runtime-config'
import { CDN_PRERENDER_ORIGIN } from './runtime-config-shared'

declare const __CONSOLE_ASSET_BUILD_ID__: string
export const ASSET_BUILD_ID =
  typeof __CONSOLE_ASSET_BUILD_ID__ === 'undefined'
    ? 'local'
    : __CONSOLE_ASSET_BUILD_ID__

export function getAssetBase(): string {
  const origin =
    typeof window === 'undefined' && process.env.TSS_PRERENDERING === 'true'
      ? CDN_PRERENDER_ORIGIN
      : getRuntimeConfig().cdnOrigin
  return origin ? `${origin}/builds/${ASSET_BUILD_ID}` : ''
}

// Only shipped public files; never API endpoints, navigation or user uploads.
const PUBLIC_ASSET =
  /^\/(?:assets\/|images\/|fonts\/|fonts-ttf\/|icons\/|favicons\/|legal\/|[^/?#]+\.(?:svg|ico|png|avif|webp|woff2?|zip|json)(?:[?#]|$))/i
export function assetUrl<T extends string | undefined | null>(
  url: T,
): T extends string ? string : T {
  return (
    typeof url === 'string' && PUBLIC_ASSET.test(url)
      ? `${getAssetBase()}${url}`
      : url
  ) as T extends string ? string : T
}

export function assetSrcSet(value: string | undefined): string | undefined {
  if (!value || value.startsWith('data:')) return value
  return value.replace(
    /(^|,\s*)(\/(?:assets|images|icons)\/[^\s,]+)/g,
    (_, separator: string, url: string) => separator + assetUrl(url),
  )
}

export function assetCss(value: string): string {
  return value.replace(
    /url\((['"]?)(\/[^)'"\s]+)\1\)/g,
    (_, quote: string, url: string) => `url(${quote}${assetUrl(url)}${quote})`,
  )
}
