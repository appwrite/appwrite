import { slugifyHeading } from '@/lib/marketing/slugify'

/** Strip inline Markdoc fragments from heading text (e.g. `## Title {% #slug %}`). */
export function stripInlineMarkdocTags(text: string): string {
  return text
    .replace(/\s*\{%\s*#[-\w]+\s*%\}/g, '')
    .replace(/\s*\{#[-\w]+\}/g, '')
    .replace(/\s*\{%[^%]*%\}/g, '')
    .replace(/\s+/g, ' ')
    .trim()
}

export function parseHeadingAnchor(text: string): { title: string; id?: string } {
  const cleaned = text.trim()
  const mdCustomId = cleaned.match(/\s*\{#([-\w]+)\}\s*$/)
  if (mdCustomId && mdCustomId.index !== undefined) {
    return {
      title: stripInlineMarkdocTags(cleaned.slice(0, mdCustomId.index).trim()),
      id: mdCustomId[1],
    }
  }

  const markdocId = cleaned.match(/\s*\{%\s*#([-\w]+)\s*%\}\s*$/)
  if (markdocId && markdocId.index !== undefined) {
    return {
      title: stripInlineMarkdocTags(cleaned.slice(0, markdocId.index).trim()),
      id: markdocId[1],
    }
  }

  return { title: stripInlineMarkdocTags(cleaned) }
}

export function resolveHeadingId(
  text: string,
  explicitId?: string,
): { title: string; id: string } {
  const { title, id: anchorId } = parseHeadingAnchor(text)
  const id = explicitId ?? anchorId ?? slugifyHeading(title)
  return { title, id }
}
