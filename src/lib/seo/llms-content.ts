/**
 * Runtime generation for llms.txt hub, docs index, and section Markdown indexes
 * from Vite-glob content modules. The build script produces the same documents
 * via the shared builders in ./llms and ./agent-discovery.
 */
import { getPublicBlogPosts } from '@/lib/blog/content'
import { getAllChangelogEntries } from '@/lib/changelog/content'
import { DOCS_PAGES } from '@/lib/docs/generated/manifest'
import { getAllIntegrationMeta } from '@/lib/integrations/content'
import {
  buildAgentSkillsDiscoveryDocument,
  buildAiCatalogDocument,
  buildMcpServerCard,
  serializeDiscoveryJson,
} from './agent-discovery'
import {
  buildAppwriteLlmsTxt,
  buildBlogMarkdownIndex,
  buildChangelogMarkdownIndex,
  buildDocsLlmsTxt,
  buildDocsMarkdownIndex,
  buildIntegrationsMarkdownIndex,
  DEFAULT_LLMS_ORIGIN,
  type AppwriteLlmsTxtData,
} from './llms'

function collectLlmsContentData(): AppwriteLlmsTxtData {
  return {
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
  }
}

/** Curated root `/llms.txt` hub. */
export function generateLlmsTxt(origin: string = DEFAULT_LLMS_ORIGIN): string {
  return buildAppwriteLlmsTxt(collectLlmsContentData(), origin)
}

/** Nested exhaustive docs index for `/docs/llms.txt`. */
export function generateDocsLlmsTxt(origin: string = DEFAULT_LLMS_ORIGIN): string {
  return buildDocsLlmsTxt(DOCS_PAGES, origin)
}

/** Top-level docs section index for `/docs.md`. */
export function generateDocsMarkdownIndex(
  origin: string = DEFAULT_LLMS_ORIGIN,
): string {
  return buildDocsMarkdownIndex(DOCS_PAGES, origin)
}

export function generateBlogMarkdownIndex(
  origin: string = DEFAULT_LLMS_ORIGIN,
): string {
  return buildBlogMarkdownIndex(collectLlmsContentData().blog, origin)
}

export function generateChangelogMarkdownIndex(
  origin: string = DEFAULT_LLMS_ORIGIN,
): string {
  return buildChangelogMarkdownIndex(collectLlmsContentData().changelog, origin)
}

export function generateIntegrationsMarkdownIndex(
  origin: string = DEFAULT_LLMS_ORIGIN,
): string {
  return buildIntegrationsMarkdownIndex(
    collectLlmsContentData().integrations,
    origin,
  )
}

export function generateMcpDiscoveryJson(
  origin: string = DEFAULT_LLMS_ORIGIN,
): string {
  return serializeDiscoveryJson(buildMcpServerCard(origin))
}

export function generateAiCatalogJson(
  origin: string = DEFAULT_LLMS_ORIGIN,
): string {
  return serializeDiscoveryJson(buildAiCatalogDocument(origin))
}

export function generateAgentSkillsDiscoveryJson(): string {
  return serializeDiscoveryJson(buildAgentSkillsDiscoveryDocument())
}
