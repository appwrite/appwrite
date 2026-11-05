import { describe, expect, test } from 'bun:test'
import {
  ALTERNATIVE_IDS,
  getAlternativePath,
} from '@/lib/alternatives/registry'
import {
  buildAlternativeMarkdownExport,
  getAllAlternativeLlmsMeta,
} from '@/lib/alternatives/markdown-export'
import { getMarketingSitemapEntries } from '@/lib/sitemap/providers/marketing'
import { buildAlternativesMarkdownIndex } from '@/lib/seo/llms'

describe('comparison pages sitemap', () => {
  test('includes every registered alternative', () => {
    const paths = new Set(getMarketingSitemapEntries().map((entry) => entry.path))
    for (const id of ALTERNATIVE_IDS) {
      expect(paths.has(getAlternativePath(id))).toBe(true)
    }
  })
})

describe('comparison markdown exports', () => {
  test('index lists every comparison with .md URLs', () => {
    const index = buildAlternativesMarkdownIndex(getAllAlternativeLlmsMeta())
    for (const id of ALTERNATIVE_IDS) {
      expect(index).toContain(`/alternative-to/${id}.md`)
    }
  })

  test('page export includes comparison tables and FAQ', () => {
    const md = buildAlternativeMarkdownExport('supabase')
    expect(md).toContain('# Appwrite vs Supabase')
    expect(md).toContain('| Feature | Appwrite | Supabase |')
    expect(md).toContain('## FAQ')
    expect(md).toContain('### Is Appwrite better than Supabase?')
  })
})
