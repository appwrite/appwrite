import { createFileRoute } from '@tanstack/react-router'
import { getPublicBlogPosts } from '@/lib/blog/content'
import { getSitemapSiteOrigin } from '@/lib/sitemap/config'
import { buildNewsSitemapXml } from '@/lib/sitemap/news'

export const Route = createFileRoute('/_api/sitemap/news.xml')({
  server: {
    handlers: {
      GET: async () => {
        const xml = buildNewsSitemapXml({
          origin: getSitemapSiteOrigin(),
          posts: getPublicBlogPosts(),
        })
        return new Response(xml, {
          headers: {
            'Content-Type': 'application/xml; charset=utf-8',
            'Cache-Control': 'public, max-age=300, stale-while-revalidate=3600',
          },
        })
      },
    },
  },
})
