'use client'

import { useEffect } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { DOCS_CONTENT_HMR_EVENT } from '@/lib/docs/docs-content-hmr-runtime'

/**
 * Soft-refresh docs preview queries on markdoc HMR.
 * Intentionally does not call router.invalidate() — that remounts the route and
 * causes scroll jump / layout shake. Article pages update in place via View.
 */
export function DocsContentHmrRefresh() {
  const queryClient = useQueryClient()

  useEffect(() => {
    const refresh = () => {
      void queryClient.invalidateQueries({ queryKey: ['docs', 'page'] })
    }

    window.addEventListener(DOCS_CONTENT_HMR_EVENT, refresh)
    return () => {
      window.removeEventListener(DOCS_CONTENT_HMR_EVENT, refresh)
    }
  }, [queryClient])

  return null
}
