import { NodeHtmlMarkdown } from 'node-html-markdown'
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

/** Markdown of a rendered page's `<main>`, starting at its title heading. */
export function pageHtmlToMarkdown(html: string, origin: string): string {
  const start = html.indexOf('<main')
  const end = html.lastIndexOf('</main>')
  const main =
    start === -1 || end === -1
      ? html
      : html.slice(start, end + '</main>'.length)

  const markdown = converter
    .translate(main)
    .replace(/\\_$/gm, '')
    .replace(/\]\(\//g, `](${origin}/`)

  const heading = markdown.search(/^# /m)
  if (heading !== -1) return `${markdown.slice(heading).trim()}\n`

  const title =
    html.match(/<title>([^<]*)<\/title>/)?.[1]?.split(' · ')[0] ?? 'Appwrite'
  return `# ${title}\n\n${markdown.trim()}\n`
}
