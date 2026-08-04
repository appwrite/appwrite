/**
 * Converts Markdoc source (docs, blog, changelog, integrations) into plain
 * CommonMark for the .md export endpoints and the llms.txt exports.
 *
 * Custom {% tags %} are rewritten to standard markdown where they carry
 * meaning (links, cards, tables, notes, media) and stripped when they are
 * purely presentational (theme wrappers, icons, layout hints).
 */

interface TagAttrs {
  id?: string
  [key: string]: string | undefined
}

function parseTagAttrs(raw: string): TagAttrs {
  const attrs: TagAttrs = {}

  const idMatch = raw.match(/#([-\w]+)/)
  if (idMatch) attrs.id = idMatch[1]

  for (const match of raw.matchAll(/([\w-]+)="([^"]*)"/g)) {
    attrs[match[1]] = match[2]
  }

  for (const match of raw.matchAll(/([\w-]+)=([^\s"%}]+)/g)) {
    attrs[match[1]] ??= match[2]
  }

  return attrs
}

function collapseInline(text: string): string {
  return text.replace(/\s+/g, ' ').trim()
}

/** Replaces fenced code blocks and inline code spans with placeholders so tag rewriting never touches code. */
function protectCode(source: string): { text: string; blocks: string[] } {
  const blocks: string[] = []

  const stash = (value: string): string => {
    blocks.push(value)
    return `\u0000CODE${blocks.length - 1}\u0000`
  }

  // Line scanner handles nested fences (e.g. ````-fences containing ```-fences),
  // which a single regex cannot pair correctly.
  const out: string[] = []
  let fence: { char: string; length: number; lines: string[] } | null = null

  for (const line of source.split('\n')) {
    if (!fence) {
      const open = line.match(/^[ \t]*(`{3,}|~{3,})/)
      if (open) {
        fence = { char: open[1][0], length: open[1].length, lines: [line] }
      } else {
        out.push(line)
      }
      continue
    }

    fence.lines.push(line)
    const close = line.match(/^[ \t]*(`{3,}|~{3,})[ \t]*$/)
    if (close && close[1][0] === fence.char && close[1].length >= fence.length) {
      out.push(stash(fence.lines.join('\n')))
      fence = null
    }
  }
  // Unterminated fence: protect through end of document.
  if (fence) out.push(stash(fence.lines.join('\n')))

  const text = out.join('\n').replace(/`[^`\n]+`/g, stash)

  return { text, blocks }
}

function restoreCode(text: string, blocks: string[]): string {
  return text.replace(/\u0000CODE(\d+)\u0000/g, (_, index: string) => {
    return blocks[Number(index)] ?? ''
  })
}

/** Markdoc list-style {% table %} blocks to GitHub pipe tables. */
function convertTables(text: string): string {
  return text.replace(
    /\{%\s*table[^%]*%\}([\s\S]*?)\{%\s*\/table\s*%\}/g,
    (original, inner: string) => {
      const rows: string[][] = []
      let current: string[] = []

      for (const line of inner.split('\n')) {
        const trimmed = line.trim()
        if (trimmed === '---') {
          if (current.length > 0) rows.push(current)
          current = []
          continue
        }

        const cellMatch = trimmed.match(/^\*\s?(.*)$/)
        if (cellMatch) {
          current.push(cellMatch[1])
          continue
        }

        // Continuation line for a multi-line cell.
        if (trimmed && current.length > 0) {
          current[current.length - 1] += ` ${trimmed}`
        }
      }
      if (current.length > 0) rows.push(current)

      if (rows.length === 0) return original

      const columnCount = Math.max(...rows.map((row) => row.length))
      const formatRow = (row: string[]): string => {
        const cells = Array.from({ length: columnCount }, (_, index) =>
          collapseInline(row[index] ?? '').replace(/\|/g, '\\|'),
        )
        return `| ${cells.join(' | ')} |`
      }

      const [header, ...body] = rows
      const separator = `| ${Array.from({ length: columnCount }, () => '---').join(' | ')} |`
      return [formatRow(header), separator, ...body.map(formatRow)].join('\n')
    },
  )
}

export function markdocToMarkdown(source: string): string {
  const { text: protectedText, blocks } = protectCode(source)
  let text = protectedText

  // Theme wrappers: drop the dark duplicate, keep the light content.
  text = text.replace(/\{%\s*only_dark\s*%\}[\s\S]*?\{%\s*\/only_dark\s*%\}/g, '')
  text = text.replace(
    /\{%\s*only_light\s*%\}([\s\S]*?)\{%\s*\/only_light\s*%\}/g,
    (_, content: string) => content.trim(),
  )

  // {% arrow_link href="..." %}text{% /arrow_link %} -> [text](href)
  text = text.replace(
    /\{%\s*arrow_link\s+([^%]*?)%\}([\s\S]*?)\{%\s*\/arrow_link\s*%\}/g,
    (_, rawAttrs: string, content: string) => {
      const { href } = parseTagAttrs(rawAttrs)
      const label = collapseInline(content)
      return href ? `[${label}](${href})` : label
    },
  )

  // {% cards_item href title %}body{% /cards_item %} -> list item with link.
  text = text.replace(
    /\{%\s*cards(?:_image)?_item\s+([^%]*?)%\}([\s\S]*?)\{%\s*\/cards(?:_image)?_item\s*%\}/g,
    (_, rawAttrs: string, content: string) => {
      const { href, title } = parseTagAttrs(rawAttrs)
      const label = title ? (href ? `[${title}](${href})` : `**${title}**`) : ''
      const body = collapseInline(content)
      if (!label) return body ? `- ${body}` : ''
      return body ? `- ${label}: ${body}` : `- ${label}`
    },
  )

  // {% section #id step=N title="..." %} -> "## N. Title"
  text = text.replace(/\{%\s*section\s+([^%]*?)%\}/g, (_, rawAttrs: string) => {
    const attrs = parseTagAttrs(rawAttrs)
    const title = attrs.title ?? attrs.id
    if (!title) return ''
    const stepPrefix = attrs.step ? `${attrs.step}. ` : ''
    return `\n## ${stepPrefix}${title}\n`
  })

  // Tab and accordion items become bold labels; notes keep a bold title.
  text = text.replace(
    /\{%\s*(tabsitem|accordion_item|info)\s+([^%]*?)%\}/g,
    (_, tag: string, rawAttrs: string) => {
      void tag
      const { title } = parseTagAttrs(rawAttrs)
      return title ? `\n**${title}**\n` : ''
    },
  )

  // {% call_to_action title description cta url /%} -> short text link.
  text = text.replace(
    /\{%\s*call_to_action\s+([\s\S]*?)\/%\}/g,
    (_, rawAttrs: string) => {
      const { title, description, cta, url } = parseTagAttrs(rawAttrs)
      const parts: string[] = []
      if (title) parts.push(`**${title}**`)
      if (description) parts.push(description)
      if (cta && url) parts.push(`[${cta}](${url})`)
      return parts.join(' ')
    },
  )

  // Media embeds become plain links; icon images become markdown images.
  text = text.replace(
    /\{%\s*(?:video|youtube)\b([\s\S]*?)\/%\}/g,
    (_, rawAttrs: string) => {
      const { src } = parseTagAttrs(rawAttrs)
      return src ? `[Video](${src})` : ''
    },
  )
  text = text.replace(
    /\{%\s*icon_image\b([^%]*?)\/%\}/g,
    (_, rawAttrs: string) => {
      const { src, alt } = parseTagAttrs(rawAttrs)
      return src ? `![${alt ?? ''}](${src})` : ''
    },
  )

  text = convertTables(text)

  // Heading anchors: "# Title {% #anchor %}" -> "# Title"
  text = text.replace(
    /^(#{1,6}[ \t]+.*?)[ \t]*\{%\s*#[-\w]+\s*%\}[ \t]*$/gm,
    (_, heading: string) => heading,
  )

  // Everything left ({% cards %}, {% tabs %}, {% multicode %}, {% icon %},
  // closing tags, layout hints, ...) is presentational: strip, keep content.
  text = text.replace(/\{%[\s\S]*?%\}/g, '')

  // Tidy whitespace left behind by removed tags: doubled mid-line spaces,
  // trailing spaces, and runs of blank lines.
  text = text.replace(/(\S)[ \t]{2,}/g, '$1 ')
  text = text.replace(/[ \t]+$/gm, '').replace(/\n{3,}/g, '\n\n')

  return restoreCode(text, blocks).trim() + '\n'
}
