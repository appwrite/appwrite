import { access, readFile } from 'node:fs/promises'
import { join } from 'node:path'
import { getDefaultSiteOrigin, resolveSiteAssetUrl } from '@/lib/marketing/site-origin'

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
  // The configured origin, never the request's: the Host header would pick the fetch target.
  const url = resolveSiteAssetUrl(`/${normalized}`, getDefaultSiteOrigin())

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
  const normalized = relativePath.replace(/^\/+/, '')
  // The path comes from an attacker-controlled image field, so a ".." segment
  // would escape the public asset root (or the site origin on the fallback)
  // and read arbitrary files. Only bundled, contained asset paths are allowed.
  if (normalized.split('/').includes('..')) {
    return null
  }
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
