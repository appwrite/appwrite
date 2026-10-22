import { access, readFile } from 'node:fs/promises'
import { join } from 'node:path'
import { resolveSiteAssetUrl } from '@/lib/marketing/site-origin'
import { getCoverRenderSiteOrigin } from '@/lib/cover-generator/render-context'
import { fetchCoverRemoteImage } from '@/lib/cover-generator/remote-image'

/**
 * Resolve bundled static assets for server-side cover rendering.
 * Dev reads from `public/`; production reads from `dist/client/`.
 * On Appwrite Sites, fall back to fetching from the deployed site's static URLs.
 */
function getCoverPublicAssetRoots(): string[] {
  const cwd = process.cwd()
  return [join(cwd, 'public'), join(cwd, 'dist/client')]
}

async function fetchCoverPublicAssetBuffer(
  relativePath: string,
): Promise<Buffer | null> {
  const normalized = relativePath.replace(/^\/+/, '')
  const url = resolveSiteAssetUrl(`/${normalized}`, getCoverRenderSiteOrigin())
  return fetchCoverRemoteImage(url)
}

export async function readCoverPublicAssetBuffer(
  relativePath: string,
): Promise<Buffer | null> {
  const normalized = relativePath.replace(/^\/+/, '')
  for (const root of getCoverPublicAssetRoots()) {
    const filepath = join(root, normalized)
    try {
      await access(filepath)
      return await readFile(filepath)
    } catch {
      // try next root
    }
  }

  return fetchCoverPublicAssetBuffer(relativePath)
}

export async function readCoverPublicAssetDataUri(
  publicSrc: string,
  mimeType: string,
): Promise<string | null> {
  const buffer = await readCoverPublicAssetBuffer(publicSrc)
  if (!buffer) return null
  return `data:${mimeType};base64,${buffer.toString('base64')}`
}
