export const DEFAULT_SITE_ORIGIN = 'https://appwrite.io'

export function getSitemapSiteOrigin(): string {
  const fromEnv =
    typeof process !== 'undefined'
      ? process.env.VITE_SITE_ORIGIN?.trim()
      : undefined
  return (fromEnv || DEFAULT_SITE_ORIGIN).replace(/\/+$/, '')
}

/** Max URLs per sitemap file (protocol limit is 50,000). */
export const SITEMAP_MAX_URLS_PER_FILE = 50_000
