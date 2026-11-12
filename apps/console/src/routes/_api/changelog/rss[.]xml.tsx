import { createFileRoute } from '@tanstack/react-router'
import { getAllChangelogEntries } from '@/lib/changelog/content'
import { CHANGELOG_DEFAULT_DESCRIPTION } from '@/lib/changelog/seo'
import { MARKETING_SITE_ORIGIN } from '@/lib/marketing/urls'
import { CHANGELOG_RSS_PATH, buildRssFeed } from '@/lib/seo/rss'

const FEED_ITEM_LIMIT = 50

export const Route = createFileRoute('/_api/changelog/rss.xml')({
  server: {
    handlers: {
      GET: async () => {
        const entries = getAllChangelogEntries().slice(0, FEED_ITEM_LIMIT)

        const feed = buildRssFeed({
          title: 'Appwrite Changelog',
          link: `${MARKETING_SITE_ORIGIN}/changelog`,
          description: CHANGELOG_DEFAULT_DESCRIPTION,
          feedUrl: `${MARKETING_SITE_ORIGIN}${CHANGELOG_RSS_PATH}`,
          items: entries.map((entry) => ({
            title: entry.title,
            link: `${MARKETING_SITE_ORIGIN}${entry.href}`,
            description: entry.description,
            date: entry.date,
            imageUrl: entry.cover,
          })),
        })

        return new Response(feed, {
          headers: {
            'Content-Type': 'application/rss+xml; charset=utf-8',
            'Cache-Control': 'public, max-age=3600, stale-while-revalidate=86400',
          },
        })
      },
    },
  },
})
