import type { DiscordAuthor, DiscordThread } from './types'
import {
  getThreadsAuthorMetaTags as buildThreadsAuthorMetaTags,
  getThreadsIndexMetaTags as buildThreadsIndexMetaTags,
  getThreadsThreadMetaTags as buildThreadsThreadMetaTags,
} from './seo'

type MetaTag = Record<string, string>

function asRouteMetaTags(tags: readonly MetaTag[]): MetaTag[] {
  return [...tags]
}

export function getThreadsIndexRouteMetaTags() {
  return asRouteMetaTags(buildThreadsIndexMetaTags() as unknown as MetaTag[])
}

export function getThreadsThreadRouteMetaTags(
  thread: DiscordThread,
  canonicalUrl: string,
) {
  return asRouteMetaTags(
    buildThreadsThreadMetaTags(thread, canonicalUrl) as unknown as MetaTag[],
  )
}

export function getThreadsAuthorRouteMetaTags(
  author: DiscordAuthor,
  canonicalUrl: string,
) {
  return asRouteMetaTags(
    buildThreadsAuthorMetaTags(author, canonicalUrl) as unknown as MetaTag[],
  )
}
