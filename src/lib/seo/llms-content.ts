/**
 * Runtime llms.txt generation from Vite-glob content modules.
 * The build script (scripts/generate-docs-exports.ts) produces the same
 * document from the filesystem via the shared builder in ./llms.
 */
import { getPublicBlogPosts } from '@/lib/blog/content'
import { getAllChangelogEntries } from '@/lib/changelog/content'
import { DOCS_PAGES } from '@/lib/docs/generated/manifest'
import { getAllIntegrationMeta } from '@/lib/integrations/content'
import { buildAppwriteLlmsTxt, DEFAULT_LLMS_ORIGIN } from './llms'

export function generateLlmsTxt(origin: string = DEFAULT_LLMS_ORIGIN): string {
  return buildAppwriteLlmsTxt(
    {
      docs: DOCS_PAGES.map((page) => ({
        slug: page.slug,
        title: page.title,
        description: page.description,
      })),
      integrations: getAllIntegrationMeta().map((integration) => ({
        slug: integration.slug,
        title: integration.title,
        description: integration.description,
      })),
      blog: getPublicBlogPosts().map((post) => ({
        slug: post.slug,
        title: post.title,
        description: post.description,
      })),
      changelog: getAllChangelogEntries().map((entry) => ({
        slug: entry.slug,
        title: entry.title,
        description: entry.description,
      })),
    },
    origin,
  )
}
