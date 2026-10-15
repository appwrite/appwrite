import { access, readFile } from 'node:fs/promises'
import path from 'node:path'

const CLIENT_DIR = path.join(process.cwd(), 'dist/client')

export function getClientStaticFile(relativePath: string): string {
  return path.join(CLIENT_DIR, relativePath)
}

export async function respondWithClientStaticFile(
  relativePath: string,
  contentType: string,
): Promise<Response> {
  const filepath = getClientStaticFile(relativePath)

  try {
    await access(filepath)
  } catch {
    return new Response('Not found', { status: 404 })
  }

  const headers = {
    'Content-Type': contentType,
    'Cache-Control': 'public, max-age=3600',
  }

  if (typeof Bun !== 'undefined') {
    // A BunFile body makes Bun override Content-Type with the file's MIME
    // type (text/plain for .txt); a stream body keeps the explicit headers.
    return new Response(Bun.file(filepath).stream(), { headers })
  }

  const body = await readFile(filepath)
  return new Response(body, { headers })
}
