import { access, readFile } from 'node:fs/promises'
import { join } from 'node:path'

/**
 * Resolve bundled static assets for server-side cover rendering.
 * Dev reads from `public/`; production images ship in `dist/client/` (see Dockerfile).
 */
function getCoverPublicAssetRoots(): string[] {
  const cwd = process.cwd()
  return [join(cwd, 'public'), join(cwd, 'dist/client')]
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
  return null
}

export async function readCoverPublicAssetDataUri(
  publicSrc: string,
  mimeType: string,
): Promise<string | null> {
  const buffer = await readCoverPublicAssetBuffer(publicSrc)
  if (!buffer) return null
  return `data:${mimeType};base64,${buffer.toString('base64')}`
}
