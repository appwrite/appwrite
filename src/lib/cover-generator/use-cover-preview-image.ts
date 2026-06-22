import { useEffect, useMemo } from 'react'
import { useQuery } from '@tanstack/react-query'
import { fetchCoverImage } from '@/lib/cover-generator/fetch-cover-image'
import { useDebouncedValue } from '@/lib/hooks/useDebouncedValue'
import type { CoverRenderData } from '@/lib/cover-generator/types'

export function useCoverPreviewImage(
  data: CoverRenderData,
  options?: { enabled?: boolean },
) {
  const enabled = options?.enabled ?? true
  const debouncedData = useDebouncedValue(data, 250)
  const previewData = useMemo(
    () => ({ ...debouncedData, format: 'png' as const }),
    [debouncedData],
  )
  const cacheKey = useMemo(() => JSON.stringify(previewData), [previewData])

  const query = useQuery({
    queryKey: ['cover-preview', cacheKey],
    queryFn: async () => {
      const blob = await fetchCoverImage(previewData)
      return URL.createObjectURL(blob)
    },
    enabled,
    staleTime: 30_000,
    retry: false,
  })

  useEffect(() => {
    const objectUrl = query.data
    return () => {
      if (objectUrl?.startsWith('blob:')) {
        URL.revokeObjectURL(objectUrl)
      }
    }
  }, [query.data])

  return query
}
