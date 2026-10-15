export type CoverLucideIconNode = [string, Record<string, string>][]

const COVER_LUCIDE_ICON_ATTRS = {
  fill: 'none',
  strokeWidth: '2',
  strokeLinecap: 'round',
  strokeLinejoin: 'round',
} as const

function escapeSvgAttr(value: string): string {
  return value
    .replace(/&/g, '&amp;')
    .replace(/"/g, '&quot;')
    .replace(/</g, '&lt;')
}

function buildCoverLucideIconChildAttrs(
  attrs: Record<string, string>,
  stroke: string,
): Record<string, string> {
  const { key: _key, ...rest } = attrs

  return {
    ...rest,
    fill: COVER_LUCIDE_ICON_ATTRS.fill,
    stroke,
    'stroke-width': COVER_LUCIDE_ICON_ATTRS.strokeWidth,
    'stroke-linecap': COVER_LUCIDE_ICON_ATTRS.strokeLinecap,
    'stroke-linejoin': COVER_LUCIDE_ICON_ATTRS.strokeLinejoin,
  }
}

export function coverLucideIconNodeToSvg(
  iconNode: CoverLucideIconNode,
  stroke = 'currentColor',
): string {
  const body = iconNode
    .map(([tag, attrs]) => {
      const childAttrs = buildCoverLucideIconChildAttrs(attrs, stroke)
      const attrString = Object.entries(childAttrs)
        .map(([key, val]) => `${key}="${escapeSvgAttr(val)}"`)
        .join(' ')
      return `<${tag} ${attrString} />`
    })
    .join('')

  return `<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="${stroke}" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">${body}</svg>`
}

export function coverLucideIconNodeToDataUri(
  iconNode: CoverLucideIconNode,
  stroke = 'currentColor',
): string {
  return `data:image/svg+xml;charset=utf-8,${encodeURIComponent(
    coverLucideIconNodeToSvg(iconNode, stroke),
  )}`
}

type LucideIconModule = {
  __iconNode?: CoverLucideIconNode
}

type LucideDynamicIconImports = Record<string, () => Promise<LucideIconModule>>

const coverLucideIconNodeCache = new Map<string, CoverLucideIconNode>()

export function getCachedCoverLucideIconNode(
  iconName: string,
): CoverLucideIconNode | null {
  return coverLucideIconNodeCache.get(iconName) ?? null
}

export async function preloadCoverLucideIconNodes(
  iconNames: Iterable<string>,
): Promise<void> {
  const pending = [...new Set(iconNames)]
    .filter((name) => name && !coverLucideIconNodeCache.has(name))
    .map(async (name) => {
      const node = await loadCoverLucideIconNode(name)
      if (node) coverLucideIconNodeCache.set(name, node)
    })

  await Promise.all(pending)
}

export async function loadCoverLucideIconNode(
  iconName: string,
): Promise<CoverLucideIconNode | null> {
  const cached = coverLucideIconNodeCache.get(iconName)
  if (cached) return cached

  try {
    const dynamicIconImports = (
      await import('lucide-react/dist/esm/dynamicIconImports.js')
    ).default as LucideDynamicIconImports

    const loader = dynamicIconImports[iconName]
    if (!loader) return null

    const mod = await loader()
    const iconNode = mod.__iconNode?.length ? mod.__iconNode : null
    if (iconNode) coverLucideIconNodeCache.set(iconName, iconNode)
    return iconNode
  } catch {
    return null
  }
}

export async function loadCoverLucideIconNames(): Promise<string[]> {
  try {
    const dynamicIconImports = (
      await import('lucide-react/dist/esm/dynamicIconImports.js')
    ).default as LucideDynamicIconImports

    return Object.keys(dynamicIconImports).sort()
  } catch {
    return []
  }
}
