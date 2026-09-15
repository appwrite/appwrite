import { describe, expect, it } from 'bun:test'
import { highlightCode } from '@/lib/blog/highlight-code'
import { renderBlogMarkdocHtml } from '@/lib/blog/render-blog-markdoc-html'

const MULTICODE = `{% multicode %}
\`\`\`client-web
const a = 1
\`\`\`

\`\`\`client-flutter
final a = 1;
\`\`\`
{% /multicode %}`

describe('renderBlogMarkdocHtml', () => {
  it('renders a multicode group as one card with a language dropdown', () => {
    const html = renderBlogMarkdocHtml(MULTICODE, { highlight: highlightCode })

    expect(html.match(/data-blog-multicode>/g)).toHaveLength(1)
    expect(html).toContain(
      `data-blog-multicode-select="${'[{&quot;id&quot;:&quot;client-web&quot;,&quot;label&quot;:&quot;Web&quot;},{&quot;id&quot;:&quot;client-flutter&quot;,&quot;label&quot;:&quot;Flutter&quot;}]'}">Web</span>`,
    )
    expect(html).toContain('data-blog-multicode-panel="client-web"')
    expect(html).toContain(
      'data-blog-multicode-panel="client-flutter" data-blog-code="final%20a%20%3D%201%3B%0A" hidden',
    )
    expect(html).toContain('class="language-javascript"')
    expect(html).toContain('class="language-dart"')
  })

  it('labels a lone fence by platform name', () => {
    const html = renderBlogMarkdocHtml('```server-nodejs\nconst a = 1\n```')

    expect(html).not.toContain('data-blog-multicode')
    expect(html).toContain('>Node.js</span>')
  })
})

describe('highlightCode', () => {
  it('resolves platform fences to their grammar', () => {
    expect(highlightCode('final a = 1;', 'client-flutter')?.language).toBe(
      'dart',
    )
    expect(highlightCode('let a = 1', 'client-apple')?.language).toBe('swift')
    expect(highlightCode('val a = 1', 'client-android-kotlin')?.language).toBe(
      'kotlin',
    )
    expect(highlightCode('const a = 1', 'server-nodejs')?.language).toBe(
      'javascript',
    )
    expect(highlightCode('x', 'plaintext')).toBeNull()
  })
})
