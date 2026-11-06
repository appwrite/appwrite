import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const packageRoot = path.resolve(
  path.dirname(fileURLToPath(import.meta.url)),
  '../../..',
)

const integrationsDirectory = path.join(
  packageRoot,
  'src/content/integrations',
)

function readSlugsFromDirectory(directory: string): string[] {
  if (!fs.existsSync(directory)) return []

  return fs
    .readdirSync(directory)
    .filter((filename) => filename.endsWith('.markdoc'))
    .map((filename) => filename.replace(/\.markdoc$/, ''))
    .sort()
}

export function getIntegrationPrerenderPaths(): string[] {
  const slugs = readSlugsFromDirectory(integrationsDirectory)
  return ['/integrations', ...slugs.map((slug) => `/integrations/${slug}`)]
}

export function isIntegrationPrerenderPath(pathname: string): boolean {
  const normalized = pathname.replace(/\/+$/, '') || '/'
  if (normalized === '/integrations') return true
  return normalized.startsWith('/integrations/')
}
