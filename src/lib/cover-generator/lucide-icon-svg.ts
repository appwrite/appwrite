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

export async function loadCoverLucideIconNode(
  iconName: string,
): Promise<CoverLucideIconNode | null> {
  try {
    const dynamicIconImports = (
      await import('lucide-react/dist/esm/dynamicIconImports.js')
    ).default as LucideDynamicIconImports

    const loader = dynamicIconImports[iconName]
    if (!loader) return null

    const mod = await loader()
    return mod.__iconNode?.length ? mod.__iconNode : null
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
