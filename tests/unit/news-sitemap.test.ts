import { spawnSync } from 'node:child_process'
import { describe, expect, test } from 'bun:test'
import { BLOG_POSTS } from '@/lib/blog/generated/manifest'
import type { BlogPostMeta } from '@/lib/blog/types'
import { getProductionRobotsTxt } from '@/lib/seo/robots'
import {
  NEWS_PUBLICATION_LANGUAGE,
  NEWS_PUBLICATION_NAME,
  NEWS_SITEMAP_MAX_URLS,
  NEWS_SITEMAP_PATH,
  NEWS_SITEMAP_XMLNS,
  SITEMAP_XMLNS,
  buildNewsSitemapXml,
  postMatchesNewsSitemapCategory,
  renderNewsSitemapXml,
  selectNewsSitemapEntries,
} from '@/lib/sitemap/news'
import { parseNewsSitemapXml } from '@/lib/sitemap/parse-news-xml'
import { renderSitemapIndexXml } from '@/lib/sitemap/xml'

function post(
  overrides: Partial<BlogPostMeta> &
    Pick<BlogPostMeta, 'slug' | 'title' | 'date' | 'category'>,
): BlogPostMeta {
  return {
    href: `/blog/post/${overrides.slug}`,
    description: 'Description',
    lastUpdated: overrides.lastUpdated ?? overrides.date,
    timeToRead: 3,
    author: 'eldad',
    hasCover: false,
    ...overrides,
  }
}

const NOW = new Date('2026-09-08T12:00:00.000Z')
const ORIGIN = 'https://appwrite.io'

describe('Google News sitemap', () => {
  test('parses Google’s news sitemap sample', () => {
    const xml = `<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
    xmlns:news="http://www.google.com/schemas/sitemap-news/0.9">
  <url>
    <loc>http://www.example.org/business/article55.html</loc>
    <news:news>
      <news:publication>
        <news:name>The Example Times</news:name>
        <news:language>en</news:language>
      </news:publication>
      <news:publication_date>2008-12-23</news:publication_date>
      <news:title>Companies A, B in Merger Talks</news:title>
    </news:news>
  </url>
</urlset>
`
    const parsed = parseNewsSitemapXml(xml)
    expect(parsed.sitemapXmlns).toBe(SITEMAP_XMLNS)
    expect(parsed.newsXmlns).toBe(NEWS_SITEMAP_XMLNS)
    expect(parsed.entries).toEqual([
      {
        loc: 'http://www.example.org/business/article55.html',
        title: 'Companies A, B in Merger Talks',
        publicationDate: '2008-12-23',
        publicationName: 'The Example Times',
        language: 'en',
      },
    ])
  })

  test('emits a valid empty urlset when nothing qualifies', () => {
    const xml = buildNewsSitemapXml({
      origin: ORIGIN,
      now: NOW,
      posts: [
        post({
          slug: 'old-announcement',
          title: 'Old news',
          date: '2026-09-01',
          category: 'announcement',
        }),
        post({
          slug: 'fresh-tutorial',
          title: 'How to',
          date: '2026-09-08',
          category: 'tutorial',
        }),
      ],
    })

    const parsed = parseNewsSitemapXml(xml)
    expect(parsed.entries).toEqual([])
    expect(xml).toContain(`xmlns="${SITEMAP_XMLNS}"`)
    expect(xml).toContain(`xmlns:news="${NEWS_SITEMAP_XMLNS}"`)
  })

  test('includes matching categories in the two-day window only', () => {
    const posts = [
      post({
        slug: 'today-news',
        title: 'Today news',
        date: '2026-09-08',
        category: 'news',
      }),
      post({
        slug: 'yesterday-announcement',
        title: 'Yesterday',
        date: '2026-09-07',
        category: 'product, announcement',
      }),
      post({
        slug: 'two-days-company',
        title: 'Company note',
        date: '2026-09-06',
        category: 'company',
      }),
      post({
        slug: 'init-recap',
        title: 'Init recap',
        date: '2026-09-08',
        category: 'init',
      }),
      post({
        slug: 'three-days-ago',
        title: 'Too old',
        date: '2026-09-05',
        category: 'announcement',
      }),
      post({
        slug: 'future',
        title: 'Scheduled',
        date: '2026-09-09',
        category: 'announcement',
      }),
      post({
        slug: 'tutorial',
        title: 'Guide',
        date: '2026-09-08',
        category: 'tutorial',
      }),
      post({
        slug: 'draft',
        title: 'Draft',
        date: '2026-09-08',
        category: 'news',
        draft: true,
      }),
      post({
        slug: 'unlisted',
        title: 'Unlisted',
        date: '2026-09-08',
        category: 'news',
        unlisted: true,
      }),
    ]

    const xml = buildNewsSitemapXml({ origin: ORIGIN, now: NOW, posts })
    const parsed = parseNewsSitemapXml(xml)
    expect(parsed.entries.map((entry) => entry.loc)).toEqual([
      `${ORIGIN}/blog/post/init-recap`,
      `${ORIGIN}/blog/post/today-news`,
      `${ORIGIN}/blog/post/yesterday-announcement`,
      `${ORIGIN}/blog/post/two-days-company`,
    ])
    expect(
      parsed.entries.every(
        (entry) =>
          entry.publicationName === NEWS_PUBLICATION_NAME &&
          entry.language === NEWS_PUBLICATION_LANGUAGE,
      ),
    ).toBe(true)
  })

  test('does not treat news as a substring of another category', () => {
    expect(
      postMatchesNewsSitemapCategory({ category: 'renews' }),
    ).toBe(false)
    expect(
      postMatchesNewsSitemapCategory({ category: 'newsletter' }),
    ).toBe(false)
    expect(postMatchesNewsSitemapCategory({ category: 'news' })).toBe(true)
  })

  test('escapes titles and still round-trips through the parser', () => {
    const xml = buildNewsSitemapXml({
      origin: ORIGIN,
      now: NOW,
      posts: [
        post({
          slug: 'ampersand',
          title: 'Postgres & MySQL: 1 < 2',
          date: '2026-09-08',
          category: 'announcement',
        }),
      ],
    })

    expect(xml).toContain('Postgres &amp; MySQL: 1 &lt; 2')
    const parsed = parseNewsSitemapXml(xml)
    expect(parsed.entries[0]?.title).toBe('Postgres & MySQL: 1 < 2')
  })

  test('accepts W3C datetimes with a timezone', () => {
    const xml = buildNewsSitemapXml({
      origin: ORIGIN,
      now: NOW,
      posts: [
        post({
          slug: 'timed',
          title: 'Timed post',
          date: '2026-09-07T08:00:00+00:00',
          category: 'news',
        }),
      ],
    })

    const parsed = parseNewsSitemapXml(xml)
    expect(parsed.entries[0]?.publicationDate).toBe('2026-09-07T08:00:00Z')
  })

  test('caps the file at Google’s 1,000 URL limit', () => {
    const posts = Array.from({ length: NEWS_SITEMAP_MAX_URLS + 25 }, (_, index) =>
      post({
        slug: `post-${index}`,
        title: `Post ${index}`,
        date: '2026-09-08',
        category: 'news',
      }),
    )

    const selected = selectNewsSitemapEntries(posts, { origin: ORIGIN, now: NOW })
    expect(selected).toHaveLength(NEWS_SITEMAP_MAX_URLS)
    parseNewsSitemapXml(renderNewsSitemapXml(selected))
  })

  test('rejects a urlset without the news namespace', () => {
    expect(() =>
      parseNewsSitemapXml(`<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
</urlset>
`),
    ).toThrow(/xmlns:news/)
  })

  test('builds parseable XML from the current blog corpus', () => {
    const xml = buildNewsSitemapXml({
      origin: ORIGIN,
      now: NOW,
      posts: BLOG_POSTS,
    })
    const parsed = parseNewsSitemapXml(xml)

    expect(parsed.entries.length).toBeGreaterThan(0)
    for (const entry of parsed.entries) {
      expect(entry.loc.startsWith(`${ORIGIN}/blog/post/`)).toBe(true)
      expect(entry.publicationName).toBe(NEWS_PUBLICATION_NAME)
      expect(['2026-09-06', '2026-09-07', '2026-09-08']).toContain(
        entry.publicationDate.slice(0, 10),
      )
    }
  })

  test('ElementTree parses generated news XML with Google namespaces', () => {
    const xml = buildNewsSitemapXml({
      origin: ORIGIN,
      now: NOW,
      posts: BLOG_POSTS,
    })

    const result = spawnSync(
      'python3',
      [
        '-c',
        `
import sys
import xml.etree.ElementTree as ET

SITEMAP_NS = "http://www.sitemaps.org/schemas/sitemap/0.9"
NEWS_NS = "http://www.google.com/schemas/sitemap-news/0.9"
root = ET.fromstring(sys.stdin.read())
assert root.tag == f"{{{SITEMAP_NS}}}urlset", root.tag
urls = list(root)
assert len(urls) > 0
for url in urls:
    assert url.tag == f"{{{SITEMAP_NS}}}url", url.tag
    loc = url.find(f"{{{SITEMAP_NS}}}loc")
    news = url.find(f"{{{NEWS_NS}}}news")
    assert loc is not None and loc.text
    assert news is not None
    publication = news.find(f"{{{NEWS_NS}}}publication")
    assert publication is not None
    assert publication.find(f"{{{NEWS_NS}}}name") is not None
    assert publication.find(f"{{{NEWS_NS}}}language") is not None
    pub_date = news.find(f"{{{NEWS_NS}}}publication_date")
    title = news.find(f"{{{NEWS_NS}}}title")
    assert pub_date is not None and pub_date.text
    assert title is not None and title.text
print(len(urls))
`,
      ],
      { input: xml, encoding: 'utf-8' },
    )

    expect(result.status).toBe(0)
    expect(result.stderr).toBe('')
    expect(Number(result.stdout.trim())).toBeGreaterThan(0)
  })
})

describe('news sitemap discovery', () => {
  test('sitemap index lists the live news sitemap', () => {
    const index = renderSitemapIndexXml(
      ORIGIN,
      [
        { id: 'blog', entries: [] },
        { id: 'news', entries: [] },
      ],
      '2026-09-08',
    )

    expect(index).toContain(`${ORIGIN}${NEWS_SITEMAP_PATH}`)
  })

  test('robots.txt advertises the news sitemap', () => {
    const robots = getProductionRobotsTxt()
    expect(robots).toContain('Sitemap: https://appwrite.io/sitemap.xml')
    expect(robots).toContain(`Sitemap: https://appwrite.io${NEWS_SITEMAP_PATH}`)
  })
})
