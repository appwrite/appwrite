import { access, readFile } from 'node:fs/promises'
import { join } from 'node:path'
import { resolveSiteAssetUrl } from '@/lib/marketing/site-origin'
import { getCoverRenderSiteOrigin } from '@/lib/cover-generator/render-context'

/**
 * Resolve bundled static assets for server-side cover rendering.
 * Dev reads from `public/`; production reads from `dist/client/`.
 * On Appwrite Sites, fall back to fetching from the deployed site's static URLs.
 */
function getCoverPublicAssetRoots(): string[] {
  const cwd = process.cwd()
  return [join(cwd, 'public'), join(cwd, 'dist/client')]
}

/**
 * Asset paths come from unauthenticated cover requests, so a `..` segment would
 * read files outside the bundled asset roots.
 */
function normalizeCoverPublicAssetPath(relativePath: string): string | null {
  const normalized = relativePath.replace(/^\/+/, '')
  if (!normalized) return null
  if (normalized.split('/').some((segment) => segment === '..')) return null
  return normalized
}

async function fetchCoverPublicAssetBuffer(
  normalized: string,
): Promise<Buffer | null> {
  const url = resolveSiteAssetUrl(`/${normalized}`, getCoverRenderSiteOrigin())

  try {
    const response = await fetch(url)
    if (!response.ok) return null
    return Buffer.from(await response.arrayBuffer())
  } catch {
    return null
  }
}

export async function readCoverPublicAssetBuffer(
  relativePath: string,
): Promise<Buffer | null> {
  const normalized = normalizeCoverPublicAssetPath(relativePath)
  if (!normalized) return null

  for (const root of getCoverPublicAssetRoots()) {
    const filepath = join(root, normalized)
    try {
      await access(filepath)
      return await readFile(filepath)
    } catch {
      // try next root
    }
  }

  return fetchCoverPublicAssetBuffer(normalized)
}

export async function readCoverPublicAssetDataUri(
  publicSrc: string,
  mimeType: string,
): Promise<string | null> {
  const buffer = await readCoverPublicAssetBuffer(publicSrc)
  if (!buffer) return null
  return `data:${mimeType};base64,${buffer.toString('base64')}`
}
