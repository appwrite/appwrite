import { getThreadsAppwriteConfig, isThreadsConfigured } from './config'
import { getAllThreadIds } from './content'
import type { SitemapEntry } from '@/lib/sitemap/types'

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
    const ids = await getAllThreadIds()
    const threadEntries = ids.map((id) => ({
      path: `/threads/${id}`,
      priority: 0.6,
      changefreq: 'monthly' as const,
    }))

    return [indexEntry, ...threadEntries]
  } catch (error) {
    console.warn('[sitemap] Failed to fetch thread IDs, including index only.', error)
    return [indexEntry]
  }
}
