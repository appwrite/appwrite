import { z } from 'zod'

export const threadsSearchSchema = z.object({
  q: z.string().optional(),
  tags: z.string().optional(),
})

export type ThreadsSearch = z.infer<typeof threadsSearchSchema>

export function parseThreadsTags(tagsParam?: string): string[] {
  if (!tagsParam?.trim()) return []
  return tagsParam
    .split(',')
    .map((tag) => tag.trim())
    .filter(Boolean)
}

export function buildThreadsRouteSearch(input: {
  q?: string
  tags?: string[]
}): ThreadsSearch {
  const q = input.q?.trim()
  const tags = input.tags?.filter(Boolean) ?? []

  return {
    ...(q ? { q } : {}),
    ...(tags.length > 0 ? { tags: tags.join(',') } : {}),
  }
}

export function toggleThreadsTag(
  selectedTags: string[],
  tag: string,
): string[] {
  if (selectedTags.includes(tag)) {
    return selectedTags.filter((item) => item !== tag)
  }
  return [...selectedTags, tag]
}
