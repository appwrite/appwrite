import { describe, expect, test } from 'bun:test'
import { getListedAlternativeIds } from '@/lib/alternatives/availability'
import {
  ALTERNATIVE_IDS,
  ALTERNATIVE_REGISTRY,
  getAlternativePath,
} from '@/lib/alternatives/registry'
import {
  buildAlternativeMarkdownExport,
  getAllAlternativeLlmsMeta,
} from '@/lib/alternatives/markdown-export'
import { getMarketingSitemapEntries } from '@/lib/sitemap/providers/marketing'
import { buildAlternativesMarkdownIndex } from '@/lib/seo/llms'

/** Comparisons waiting for a product launch (Appwrite Reviews). */
const UNLAUNCHED_IDS = ALTERNATIVE_IDS.filter((id) => ALTERNATIVE_REGISTRY[id].launchesWith)

describe('comparison pages sitemap', () => {
  test('includes every launched alternative', () => {
    const paths = new Set(getMarketingSitemapEntries().map((entry) => entry.path))
    for (const id of getListedAlternativeIds()) {
      expect(paths.has(getAlternativePath(id))).toBe(true)
    }
  })

  test('leaves out comparisons that wait for a launch', () => {
    const paths = new Set(getMarketingSitemapEntries().map((entry) => entry.path))
    expect(UNLAUNCHED_IDS.length).toBeGreaterThan(0)
    for (const id of UNLAUNCHED_IDS) {
      expect(paths.has(getAlternativePath(id))).toBe(false)
    }
  })
})

describe('comparison markdown exports', () => {
  test('index lists every launched comparison with .md URLs', () => {
    const index = buildAlternativesMarkdownIndex(getAllAlternativeLlmsMeta())
    for (const id of getListedAlternativeIds()) {
      expect(index).toContain(`/alternative-to/${id}.md`)
    }
    for (const id of UNLAUNCHED_IDS) {
      expect(index).not.toContain(`/alternative-to/${id}.md`)
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
