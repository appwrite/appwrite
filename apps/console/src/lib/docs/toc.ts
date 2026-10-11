import { stripInlineMarkdocTags } from '@/lib/docs/markdoc-heading'
import { slugifyHeading } from '@/lib/marketing/slugify'
import { stripFrontmatter } from './frontmatter'
import type { DocsTocItem } from './types'

function parseSectionAttributes(
  attrs: string,
): { id: string; title: string; step?: number } | null {
  const idMatch = attrs.match(/#([-\w]+)/)
  if (!idMatch) return null
  const stepMatch = attrs.match(/\bstep=(\d+)\b/)
  const titleMatch = attrs.match(/\btitle="([^"]*)"/)
  return {
    id: idMatch[1],
    title: stripInlineMarkdocTags(titleMatch ? titleMatch[1] : ''),
    step: stepMatch ? Number(stepMatch[1]) : undefined,
  }
}

/**
 * Blank out fenced code blocks, keeping the line count intact. A shell comment such as
 * `# Create the bucket` is not a heading, so it must not reach the heading scan below.
 */
function blankFencedCode(body: string): string {
  let fence: string | null = null

  return body
    .split('\n')
    .map((line) => {
      const marker = line.match(/^\s*(`{3,}|~{3,})/)?.[1]
      if (fence === null) {
        if (marker) fence = marker
        return marker ? '' : line
      }
      if (marker && marker[0] === fence[0] && marker.length >= fence.length) fence = null
      return ''
    })
    .join('\n')
}

export function extractDocsToc(raw: string): DocsTocItem[] {
  const body = blankFencedCode(stripFrontmatter(raw))
  const items: DocsTocItem[] = []
  const seen = new Set<string>()

  const sectionTagRe = /\{%\s*section\s+([^%]+?)\s*%\}/g
  let match: RegExpExecArray | null
  while ((match = sectionTagRe.exec(body)) !== null) {
    const parsed = parseSectionAttributes(match[1])
    if (!parsed || seen.has(parsed.id)) continue
    seen.add(parsed.id)
    items.push({
      id: parsed.id,
      label: parsed.title || parsed.id,
      level: 2,
      step: parsed.step,
    })
  }

  const headingRe = /^(#{1,2})(?!#)\s+(.+)$/gm
  while ((match = headingRe.exec(body)) !== null) {
    const level = match[1].length
    const rest = match[2].trim()
    const markdocId = rest.match(/\s*\{%\s*#([-\w]+)\s*%\}\s*$/)
    const mdCustomId = rest.match(/\s*\{#([-\w]+)\}\s*$/)

    let label: string
    let id: string
    if (markdocId) {
      label = stripInlineMarkdocTags(rest.slice(0, markdocId.index).trim())
      id = markdocId[1]
    } else if (mdCustomId) {
      label = stripInlineMarkdocTags(rest.slice(0, mdCustomId.index).trim())
      id = mdCustomId[1]
    } else {
      label = stripInlineMarkdocTags(rest)
      id = slugifyHeading(label)
    }

    if (!id || seen.has(id)) continue
    seen.add(id)
    items.push({ id, label, level })
  }

  return items
}
