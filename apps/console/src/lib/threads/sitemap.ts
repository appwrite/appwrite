import { getThreadsAppwriteConfig, isThreadsConfigured } from './config'
import { getThreadPublicId, iterateAllThreads } from './content'
import type { SitemapEntry } from '@/lib/sitemap/types'

function toSitemapLastmod(value: string | undefined): string | undefined {
  if (!value?.trim()) return undefined
  const parsed = new Date(value)
  if (Number.isNaN(parsed.getTime())) return undefined
  return parsed.toISOString().slice(0, 10)
}

function shouldSkipThreadsSitemap(): boolean {
  const { endpoint } = getThreadsAppwriteConfig()
  return endpoint.includes('appwrite.test')
}

export async function fetchThreadsSitemapEntries(): Promise<SitemapEntry[]> {
  const indexEntry: SitemapEntry = {
    path: '/threads',
    priority: 0.8,
    changefreq: 'weekly',
  }

  if (!isThreadsConfigured() || shouldSkipThreadsSitemap()) {
    return [indexEntry]
  }

  try {
    const threadEntries: SitemapEntry[] = []
    for await (const thread of iterateAllThreads()) {
      threadEntries.push({
        path: `/threads/${getThreadPublicId(thread)}`,
        lastmod: toSitemapLastmod(
          thread.last_activity ?? thread.$updatedAt ?? thread.$createdAt,
        ),
        priority: 0.6,
        changefreq: 'monthly',
      })
    }

    return [indexEntry, ...threadEntries]
  } catch (error) {
    console.warn('[sitemap] Failed to fetch thread IDs, including index only.', error)
    return [indexEntry]
  }
}
