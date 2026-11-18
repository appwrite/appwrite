import { describe, expect, test } from 'bun:test'
import { preprocessBlogMarkdocContent } from '@/lib/blog/preprocess'

describe('preprocessBlogMarkdocContent', () => {
  test('turns link-only list items into arrow links', () => {
    expect(
      preprocessBlogMarkdocContent(
        [
          '- [Docs](https://appwrite.io/docs)',
          '- [Wiki](https://en.wikipedia.org/wiki/Backend_(software))',
          'Plain text',
        ].join('\n'),
      ),
    ).toBe(
      [
        '{% arrow_link href="https://appwrite.io/docs" %}',
        'Docs',
        '{% /arrow_link %}',
        '{% arrow_link href="https://en.wikipedia.org/wiki/Backend_(software)" %}',
        'Wiki',
        '{% /arrow_link %}',
        'Plain text',
      ].join('\n'),
    )
  })

  test('leaves a line of nested parentheses alone without backtracking', () => {
    const line = '* [x](' + 'a(()'.repeat(5_000) + '!'
    const started = performance.now()

    expect(preprocessBlogMarkdocContent(line)).toBe(line)
    expect(performance.now() - started).toBeLessThan(1_000)
  })
})
