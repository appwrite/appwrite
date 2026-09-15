import Markdoc, { type RenderableTreeNode } from '@markdoc/markdoc'
import { resolveHeadingId } from '@/lib/docs/markdoc-heading'
import { docsMarkdocConfig } from '@/lib/docs/markdoc-config'
import { resolveFenceCodeLabel } from '@/lib/code-language'
import { HEADING_LINK_TEXT_CLASS, INLINE_LINK_CLASS } from '@/lib/link-styles'
import { MARKETING_SITE_ORIGIN, parseBlogPagePath } from '@/lib/marketing/urls'

const HEADING_CLASS_BY_LEVEL: Record<number, string> = {
  1: 'scroll-mt-24 font-aeonik-pro text-foreground/95 text-balance mb-4 mt-8 text-[20px] font-normal leading-[1.3] first:mt-0 @[640px]:text-[22px]',
  2: 'scroll-mt-24 font-aeonik-pro text-foreground/95 text-balance mb-3 mt-7 text-[17px] font-semibold leading-snug @[640px]:text-[18px]',
  3: 'scroll-mt-24 font-aeonik-pro text-foreground/95 text-balance mb-2 mt-6 text-[16px] font-semibold @[640px]:text-[17px]',
  4: 'scroll-mt-24 font-aeonik-pro text-foreground/95 text-balance mb-2 mt-5 text-[15px] font-semibold',
}

const COPY_ICON_SVG =
  '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="size-full -rotate-45" aria-hidden="true"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>'

function escapeHtml(value: string): string {
  return value
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;')
}

function attr(name: string, value: string | number | boolean | undefined): string {
  if (value === undefined || value === false) return ''
  if (value === true) return ` ${name}`
  return ` ${name}="${escapeHtml(String(value))}"`
}

type MarkdocTag = {
  $$mdtype: 'Tag'
  name: string
  attributes: Record<string, unknown>
  children: RenderableTreeNode[]
}

function isTag(node: RenderableTreeNode): node is MarkdocTag {
  return Boolean(
    node &&
      typeof node === 'object' &&
      !Array.isArray(node) &&
      '$$mdtype' in node &&
      node.$$mdtype === 'Tag',
  )
}

function collectText(node: RenderableTreeNode): string {
  if (node == null || typeof node === 'boolean') return ''
  if (typeof node === 'string' || typeof node === 'number') return String(node)
  if (Array.isArray(node)) return node.map(collectText).join('')
  if (isTag(node)) return node.children.map(collectText).join('')
  return ''
}

function renderChildren(nodes: RenderableTreeNode[]): string {
  return nodes.map(renderNode).join('')
}

function resolveHref(href: string): {
  href: string
  external: boolean
} {
  const blogPath = parseBlogPagePath(href)
  const resolved = blogPath ?? href
  const external =
    resolved.startsWith('http') ||
    resolved.startsWith('//') ||
    resolved.startsWith('mailto:') ||
    resolved.startsWith('tel:')

  if (resolved.startsWith('/') && !resolved.startsWith('/docs') && !resolved.startsWith('/blog') && !resolved.startsWith('/changelog') && !resolved.startsWith('/home') && !resolved.startsWith('/pricing') && !resolved.startsWith('/company') && !resolved.startsWith('/community') && !resolved.startsWith('/startups') && !resolved.startsWith('/education') && !resolved.startsWith('/partners') && !resolved.startsWith('/integrations') && !resolved.startsWith('/threads') && !resolved.startsWith('/terms') && !resolved.startsWith('/privacy') && !resolved.startsWith('/cookies') && !resolved.startsWith('/assets')) {
    return {
      href: `${MARKETING_SITE_ORIGIN}${resolved}`,
      external: true,
    }
  }

  return { href: resolved, external }
}

function renderAnchor(
  href: string | undefined,
  children: string,
  className = INLINE_LINK_CLASS,
): string {
  if (!href) return `<span>${children}</span>`

  const resolved = resolveHref(href)
  const extra =
    resolved.external && !href.startsWith('#')
      ? ' target="_blank" rel="noopener noreferrer"'
      : ''

  return `<a href="${escapeHtml(resolved.href)}" class="${className}"${extra}>${children}</a>`
}

function headingClass(level: number): string {
  return HEADING_CLASS_BY_LEVEL[Math.min(level, 4)] ?? HEADING_CLASS_BY_LEVEL[4]
}

function renderHeading(tag: MarkdocTag): string {
  const level = Number(tag.attributes.level ?? 1)
  const text = collectText(tag)
  const { title, id } = resolveHeadingId(text, tag.attributes.id as string | undefined)
  const displayLevel = Math.min(level + 1, 6)
  const tagName = `h${displayLevel}`
  const iconSize =
    level === 1 ? 'size-[18px]' : level === 2 ? 'size-4' : 'size-3.5'

  return `<${tagName} id="${escapeHtml(id)}" class="${headingClass(level)} group"><span class="inline-flex max-w-full items-center gap-2"><a href="#${escapeHtml(id)}" class="${HEADING_LINK_TEXT_CLASS}">${escapeHtml(title)}</a><button type="button" class="${iconSize} inline-flex shrink-0 cursor-pointer items-center justify-center rounded-sm text-muted-foreground opacity-0 transition-opacity duration-150 hover:text-foreground focus:outline-none focus-visible:opacity-100 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background group-hover:opacity-100 group-focus-within:opacity-100" data-blog-heading-copy="${escapeHtml(id)}" aria-label="Copy link">${COPY_ICON_SVG}</button></span></${tagName}>`
}

export type BlogCodeHighlighter = (
  code: string,
  language: string,
) => { html: string; language: string } | null

// Set for the duration of one synchronous render so the recursive node
// walkers do not need an options parameter threaded through every call.
let activeHighlighter: BlogCodeHighlighter | null = null

const CODE_CARD_CLASS =
  'not-prose my-4 w-full overflow-hidden rounded-xl border border-border bg-card/50'
const CODE_HEADER_CLASS =
  'flex items-center justify-between gap-2 border-b border-border px-3 py-1.5'
const CODE_LABEL_CLASS = 'text-[12px] font-medium text-muted-foreground'
const CODE_COPY_BUTTON_CLASS =
  'h-7 shrink-0 rounded-md px-2 text-[12px] text-muted-foreground hover:text-foreground'
type FenceSnippet = {
  /** Fence info string as written, e.g. `client-flutter` or `python`. */
  language: string
  content: string
  /** URI-encoded content for the copy button. */
  encoded: string
}

function fenceSnippet(tag: MarkdocTag): FenceSnippet {
  const content = String(tag.attributes.content ?? collectText(tag))
  return {
    language: String(tag.attributes.language ?? 'plaintext'),
    content,
    encoded: encodeURIComponent(content),
  }
}

function renderCodePre({ content, language }: FenceSnippet): string {
  const highlighted = activeHighlighter?.(content, language) ?? null
  const codeClass = highlighted
    ? ` class="language-${escapeHtml(highlighted.language)}"`
    : ''
  const body = highlighted ? highlighted.html : escapeHtml(content)
  return `<pre class="blog-code overflow-x-auto p-4 font-mono text-[13px] leading-6 text-foreground/90"><code${codeClass}>${body}</code></pre>`
}

function renderCopyButton(encoded: string): string {
  return `<button type="button" class="${CODE_COPY_BUTTON_CLASS}" data-blog-copy="${encoded}">Copy</button>`
}

function renderFence(tag: MarkdocTag): string {
  const snippet = fenceSnippet(tag)
  return `<div class="${CODE_CARD_CLASS}"><div class="${CODE_HEADER_CLASS}"><span class="${CODE_LABEL_CLASS}">${escapeHtml(resolveFenceCodeLabel(snippet.language))}</span>${renderCopyButton(snippet.encoded)}</div>${renderCodePre(snippet)}</div>`
}

/**
 * One card for a `{% multicode %}` group: one pre-highlighted panel per
 * fence, only the first visible, and a header placeholder that
 * BlogMarkdownBody replaces with the shared Select. The placeholder carries
 * the language list and shows the first label until React mounts.
 */
function renderMultiCode(tag: MarkdocTag): string {
  const snippets: FenceSnippet[] = []
  for (const child of tag.children) {
    if (!isTag(child) || child.name !== 'Fence') continue
    const snippet = fenceSnippet(child)
    if (snippets.some((entry) => entry.language === snippet.language)) continue
    snippets.push(snippet)
  }
  if (snippets.length === 0) return renderChildren(tag.children)
  if (snippets.length === 1) {
    return renderFence(
      tag.children.find(
        (child): child is MarkdocTag => isTag(child) && child.name === 'Fence',
      )!,
    )
  }

  const languages = snippets.map((snippet) => ({
    id: snippet.language,
    label: resolveFenceCodeLabel(snippet.language),
  }))
  const panels = snippets
    .map(
      (snippet, index) =>
        `<div data-blog-multicode-panel="${escapeHtml(snippet.language)}" data-blog-code="${snippet.encoded}"${attr('hidden', index > 0)}>${renderCodePre(snippet)}</div>`,
    )
    .join('')

  return `<div class="${CODE_CARD_CLASS}" data-blog-multicode><div class="${CODE_HEADER_CLASS}"><span class="${CODE_LABEL_CLASS}" data-blog-multicode-select="${escapeHtml(JSON.stringify(languages))}">${escapeHtml(languages[0].label)}</span>${renderCopyButton(snippets[0].encoded)}</div>${panels}</div>`
}

function renderImage(tag: MarkdocTag): string {
  const src = String(tag.attributes.src ?? '')
  if (!src) return ''
  const alt = String(tag.attributes.alt ?? '')
  const title = tag.attributes.title as string | undefined
  const contain = title === 'contain'

  return `<button type="button" class="not-prose my-6 block w-full cursor-pointer overflow-hidden rounded-xl border border-border bg-muted/20" data-blog-image-src="${escapeHtml(src)}" data-blog-image-alt="${escapeHtml(alt)}" aria-label="${escapeHtml(alt || 'Expand image')}"><img src="${escapeHtml(src)}" alt="${escapeHtml(alt)}" loading="lazy" class="h-full w-full rounded-lg object-contain${contain ? ' p-3 @[480px]:p-4' : ''}" data-blog-image /></button>`
}

function renderInfo(tag: MarkdocTag): string {
  const title = String(tag.attributes.title ?? '')
  return `<aside class="not-prose my-6 rounded-xl border border-border bg-card/50 px-4 py-3"><p class="text-[13px] font-medium text-foreground">${escapeHtml(title)}</p><div class="mt-2 text-[15px] leading-relaxed text-muted-foreground">${renderChildren(tag.children)}</div></aside>`
}

function renderCallToAction(tag: MarkdocTag): string {
  const href = String(tag.attributes.href ?? tag.attributes.url ?? '')
  const label = String(tag.attributes.cta ?? tag.attributes.title ?? 'Get started')
  if (!href) return ''
  const resolved = resolveHref(href)
  const extra = resolved.external
    ? ' target="_blank" rel="noopener noreferrer"'
    : ''
  return `<div class="not-prose my-6"><a href="${escapeHtml(resolved.href)}" class="inline-flex items-center rounded-lg bg-[var(--brand-cta)] px-4 py-2 text-[13px] font-medium text-white hover:opacity-90"${extra}>${escapeHtml(label)}</a></div>`
}

function renderArrowLink(tag: MarkdocTag): string {
  const href = String(tag.attributes.href ?? '')
  if (!href) return ''
  return `<div class="changelog-resource-link not-prose">${renderAnchor(
    href,
    `<span class="min-w-0">${renderChildren(tag.children)}</span>`,
    'relative block w-full rounded-none px-4 py-4 pe-10 text-start text-[13px] font-medium leading-5 text-foreground transition-colors duration-150 hover:bg-muted/40',
  )}</div>`
}

function renderYoutube(tag: MarkdocTag): string {
  const id = String(tag.attributes.id ?? '').trim()
  const src = String(tag.attributes.src ?? '').trim()
  const title = String(tag.attributes.title ?? 'YouTube video')
  const embed =
    src || (id ? `https://www.youtube-nocookie.com/embed/${id}` : '')
  if (!embed) return ''
  const thumbnail =
    String(tag.attributes.thumbnail ?? '').trim() ||
    (id ? `https://i.ytimg.com/vi/${id}/hqdefault.jpg` : '')

  return `<div class="not-prose my-8"><button type="button" class="group relative block w-full cursor-pointer overflow-hidden rounded-xl border border-border bg-muted/25 text-start" data-blog-youtube="${escapeHtml(embed)}" aria-label="${escapeHtml(`Play ${title}`)}">${
    thumbnail
      ? `<span class="relative aspect-video block w-full"><img src="${escapeHtml(thumbnail)}" alt="" loading="lazy" class="size-full object-cover" /></span>`
      : '<span class="aspect-video block w-full bg-muted"></span>'
  }</button></div>`
}

function renderTag(tag: MarkdocTag): string {
  const name = tag.name
  const children = renderChildren(tag.children)

  switch (name) {
    case 'Heading':
      return renderHeading(tag)
    case 'Link':
      return renderAnchor(tag.attributes.href as string | undefined, children)
    case 'Image':
      return renderImage(tag)
    case 'Fence':
      return renderFence(tag)
    case 'MultiCode':
      return renderMultiCode(tag)
    case 'Info':
      return renderInfo(tag)
    case 'CallToAction':
      return renderCallToAction(tag)
    case 'ArrowLink':
      return renderArrowLink(tag)
    case 'Youtube':
      return renderYoutube(tag)
    case 'Video': {
      const src = String(tag.attributes.src ?? '')
      if (!src) return ''
      return `<div class="not-prose my-6 overflow-hidden rounded-xl border border-border"><video src="${escapeHtml(src)}" controls class="w-full"${attr('title', tag.attributes.title as string | undefined)}></video></div>`
    }
    case 'Blockquote':
      return `<blockquote class="my-5 border-s-2 border-[var(--brand-cta)] ps-4 italic">${children}</blockquote>`
    case 'OnlyLight':
      return `<div class="dark:hidden">${children}</div>`
    case 'OnlyDark':
      return `<div class="hidden dark:block">${children}</div>`
    case 'Tabs':
    case 'TabsItem':
    case 'Accordion':
    case 'AccordionItem':
    case 'Cards':
    case 'CardsItem':
    case 'Section':
      return children
    case 'MarkdocTableTag':
      return children
    case 'MarkdocTableRoot':
      return `<div class="my-6 overflow-x-auto"><table class="w-full caption-bottom text-sm">${children}</table></div>`
    case 'MarkdocTableHeader':
      return `<thead>${children}</thead>`
    case 'MarkdocTableBody':
      return `<tbody>${children}</tbody>`
    case 'MarkdocTableRow':
      return `<tr class="border-b border-border">${children}</tr>`
    case 'MarkdocTableHead':
      return `<th class="px-4 py-3 text-start text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">${children}</th>`
    case 'MarkdocTableCell':
      return `<td class="px-4 py-3 text-[14px] leading-6 text-muted-foreground">${children}</td>`
    case 'p':
      return `<p>${children}</p>`
    case 'article':
    case 'div':
      return children
    default:
      if (/^[a-z][a-z0-9-]*$/.test(name)) {
        return `<${name}>${children}</${name}>`
      }
      return children
  }
}

function renderNode(node: RenderableTreeNode): string {
  if (node == null || typeof node === 'boolean') return ''
  if (typeof node === 'number') return escapeHtml(String(node))
  if (typeof node === 'string') return escapeHtml(node)
  if (Array.isArray(node)) return renderChildren(node)
  if (isTag(node)) return renderTag(node)
  return ''
}

export function renderBlogMarkdocHtml(
  content: string,
  options?: { highlight?: BlogCodeHighlighter },
): string {
  const previous = activeHighlighter
  activeHighlighter = options?.highlight ?? null
  try {
    const ast = Markdoc.parse(content)
    const transformed = Markdoc.transform(ast, docsMarkdocConfig)
    return renderNode(transformed)
  } finally {
    activeHighlighter = previous
  }
}

export async function renderBlogPostBodies(post: {
  content: string
  faqs?: Array<{ question: string; answer: string }>
}) {
  // Prism and its grammars load as their own chunk, only when a post renders.
  const { highlightCode } = await import('./highlight-code')
  const options = { highlight: highlightCode }
  return {
    contentHtml: renderBlogMarkdocHtml(post.content, options),
    faqs: (post.faqs ?? []).map((faq) => ({
      question: faq.question,
      html: renderBlogMarkdocHtml(faq.answer, options),
    })),
  }
}
