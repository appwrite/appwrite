const partialModules = import.meta.glob('/src/content/docs-partials/*.md', {
  query: '?raw',
  import: 'default',
  eager: true,
}) as Record<string, string>

const partialCache = new Map<string, string>()

for (const [path, content] of Object.entries(partialModules)) {
  const fileName = path.split('/').pop()
  if (fileName) partialCache.set(fileName, content)
}

export function resolvePartials(content: string): string {
  const partialRegex = /\{%\s*partial\s+file="([^"]+)"\s*\/%\}/g
  return content.replace(partialRegex, (_, fileName: string) => {
    return partialCache.get(fileName) ?? ''
  })
}
