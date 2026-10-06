import { getAlternativeContent } from '@/lib/alternatives/content'
import {
  ALTERNATIVE_IDS,
  ALTERNATIVE_REGISTRY,
  ALTERNATIVES_VERIFIED_ON,
  getAlternativePath,
  parseAlternativeRouteParam,
} from '@/lib/alternatives/registry'
import type {
  AlternativeId,
  ComparisonCell,
  ComparisonValue,
  FairPlayPoint,
} from '@/lib/alternatives/types'
import type { LlmsContentMeta } from '@/lib/seo/llms'
import { DEFAULT_LLMS_ORIGIN } from '@/lib/seo/llms'

export function getAllAlternativeLlmsMeta(): LlmsContentMeta[] {
  return ALTERNATIVE_IDS.map((id) => {
    const meta = ALTERNATIVE_REGISTRY[id]
    return {
      slug: id,
      title: meta.metaTitle,
      description: meta.metaDescription,
    }
  }).sort((a, b) => a.title.localeCompare(b.title))
}

function normalizeCell(cell: ComparisonCell): {
  value: ComparisonValue
  note?: string
} {
  if (typeof cell === 'object') return cell
  return { value: cell }
}

function comparisonValueToText(value: ComparisonValue): string {
  if (value === true) return 'Yes'
  if (value === false) return 'No'
  if (value === 'partial') return 'Partial'
  if (value === 'soon') return 'Coming soon'
  return value
}

function escapeTableCell(text: string): string {
  return text.replace(/\|/g, '\\|').replace(/\n/g, ' ')
}

function formatComparisonCell(cell: ComparisonCell): string {
  const { value, note } = normalizeCell(cell)
  const main = comparisonValueToText(value)
  if (!note) return escapeTableCell(main)
  return escapeTableCell(`${main} (${note})`)
}

function formatFairPlayPoint(point: FairPlayPoint): string {
  if (typeof point === 'string') return point
  if (point.aside) return `${point.text} (${point.aside})`
  return point.text
}

function absoluteHref(origin: string, href: string): string {
  if (href.startsWith('http://') || href.startsWith('https://')) return href
  return `${origin}${href.startsWith('/') ? href : `/${href}`}`
}

/** Structured Markdown for one /alternative-to/{id} page (English source copy). */
export function buildAlternativeMarkdownExport(
  id: AlternativeId,
  origin: string = DEFAULT_LLMS_ORIGIN,
): string {
  const meta = ALTERNATIVE_REGISTRY[id]
  const content = getAlternativeContent(id)
  const htmlUrl = `${origin}${getAlternativePath(id)}`

  const lines: string[] = [
    `# ${meta.metaTitle}`,
    '',
    `> ${meta.metaDescription}`,
    '',
    `- HTML: ${htmlUrl}`,
    `- Competitor: ${meta.name} (${meta.category})`,
    `- Facts verified: ${ALTERNATIVES_VERIFIED_ON}`,
    '',
  ]

  for (const group of content.comparison) {
    lines.push(`## ${group.title}`, '')
    lines.push(`| Feature | Appwrite | ${meta.name} |`)
    lines.push('| --- | --- | --- |')
    for (const row of group.rows) {
      lines.push(
        `| ${escapeTableCell(row.label)} | ${formatComparisonCell(row.appwrite)} | ${formatComparisonCell(row.competitor)} |`,
      )
    }
    lines.push('')
  }

  lines.push(`## ${content.fairPlay.title}`, '')
  lines.push(content.fairPlay.description, '')
  for (const point of content.fairPlay.points) {
    lines.push(`- ${formatFairPlayPoint(point)}`)
  }
  lines.push('')

  if (content.related.length > 0) {
    lines.push('## Related reading', '')
    for (const link of content.related) {
      lines.push(
        `- [${link.title}](${absoluteHref(origin, link.href)}): ${link.description}`,
      )
    }
    lines.push('')
  }

  if (content.faq.length > 0) {
    lines.push('## FAQ', '')
    for (const item of content.faq) {
      lines.push(`### ${item.question}`, '')
      lines.push(item.answer, '')
      if (item.links?.length) {
        for (const link of item.links) {
          lines.push(`- [${link.label}](${absoluteHref(origin, link.href)})`)
        }
        lines.push('')
      }
    }
  }

  if (content.sources.length > 0) {
    lines.push('## Sources', '')
    for (const source of content.sources) {
      lines.push(`- [${source.label}](${source.href})`)
    }
    lines.push('')
  }

  return `${lines.join('\n').trim()}\n`
}

export function getAlternativeMarkdownExport(
  routeParam: string,
): string | null {
  const id = parseAlternativeRouteParam(routeParam)
  if (!id) return null
  return buildAlternativeMarkdownExport(id)
}
