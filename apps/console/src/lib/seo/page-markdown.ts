import { NodeHtmlMarkdown } from 'node-html-markdown'
import { markdownFrontmatter } from '@/lib/seo/markdown-negotiation'
import type {
  TranslatorConfigFactory,
  TranslatorConfigObject,
} from 'node-html-markdown'

const hidden: TranslatorConfigFactory = ({ node }) =>
  node.getAttribute('aria-hidden') === 'true' ? { ignore: true } : {}

const translators: TranslatorConfigObject = Object.fromEntries(
  ['aside', 'div', 'figure', 'section', 'span'].map((tag) => [tag, hidden]),
)

const converter = new NodeHtmlMarkdown(
  {
    ignore: [
      'button',
      'canvas',
      'form',
      'nav',
      'noscript',
      'script',
      'style',
      'svg',
      'template',
      'video',
    ],
    keepDataImages: false,
  },
  translators,
)

function decodeEntities(text: string): string {
  return text
    .replace(/&quot;/g, '"')
    .replace(/&#x27;|&#39;/g, "'")
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/&amp;/g, '&')
}

function metaContent(html: string, name: string): string | undefined {
  const content = html.match(
    new RegExp(`<meta name="${name}" content="([^"]*)"`),
  )?.[1]
  return content === undefined ? undefined : decodeEntities(content)
}

/** Markdown of a rendered page's `<main>`, with frontmatter and starting at its title heading. */
export function pageHtmlToMarkdown(
  html: string,
  origin: string,
  page: string,
): string {
  const start = html.indexOf('<main')
  const end = html.lastIndexOf('</main>')
  const main =
    start === -1 || end === -1
      ? html
      : html.slice(start, end + '</main>'.length)

  const markdown = converter
    .translate(main.replace(/(href|src)="\/(?!\/)/g, `$1="${origin}/`))
    .replace(/\\_$/gm, '')

  const title = decodeEntities(
    html.match(/<title>([^<]*)<\/title>/)?.[1]?.split(' · ')[0] ?? 'Appwrite',
  )
  const description = metaContent(html, 'description')
  const frontmatter = markdownFrontmatter({
    title,
    ...(description ? { description } : {}),
    canonical: `${origin}${page}`,
  })

  const heading = markdown.search(/^# /m)
  const body =
    heading === -1
      ? `# ${title}\n\n${markdown.trim()}`
      : markdown.slice(heading).trim()
  return `${frontmatter}${body}\n`
}
