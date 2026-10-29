const partialLoaders = import.meta.glob('/src/content/docs-partials/*.md', {
  query: '?raw',
  import: 'default',
}) as Record<string, () => Promise<string>>

const partialPathByFileName = new Map<string, string>()
for (const modulePath of Object.keys(partialLoaders)) {
  const fileName = modulePath.split('/').pop()
  if (fileName) partialPathByFileName.set(fileName, modulePath)
}

const partialCache = new Map<string, string>()

async function loadPartial(fileName: string): Promise<string> {
  if (!import.meta.env.DEV) {
    const cached = partialCache.get(fileName)
    if (cached !== undefined) return cached
  }

  const modulePath = partialPathByFileName.get(fileName)
  if (!modulePath) {
    partialCache.set(fileName, '')
    return ''
  }

  if (import.meta.env.DEV && typeof window !== 'undefined') {
    try {
      const response = await fetch(`${modulePath}?t=${Date.now()}`)
      if (response.ok) {
        const content = await response.text()
        partialCache.set(fileName, content)
        return content
      }
    } catch {
      // Fall through to glob loader.
    }
  }

  const loader = partialLoaders[modulePath]
  if (!loader) {
    partialCache.set(fileName, '')
    return ''
  }

  const content = await loader()
  partialCache.set(fileName, content)
  return content
}

export function resolvePartials(content: string): string {
  const partialRegex = /\{%\s*partial\s+file="([^"]+)"\s*\/%\}/g
  return content.replace(partialRegex, (_, fileName: string) => {
    return partialCache.get(fileName) ?? ''
  })
}

/** Load referenced partials into cache before synchronous {@link resolvePartials}. */
export async function preloadPartialsForContent(content: string): Promise<void> {
  const partialRegex = /\{%\s*partial\s+file="([^"]+)"\s*\/%\}/g
  const fileNames = new Set<string>()
  for (const match of content.matchAll(partialRegex)) {
    fileNames.add(match[1]!)
  }
  await Promise.all([...fileNames].map((fileName) => loadPartial(fileName)))
}
