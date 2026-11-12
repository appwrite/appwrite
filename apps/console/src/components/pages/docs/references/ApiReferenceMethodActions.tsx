'use client'

import { useCallback } from 'react'
import { buildApiReferenceMethodMarkdown } from '@/lib/docs/references/method-markdown'
import type {
  ReferencePlatform,
  ReferenceVersion,
} from '@/lib/docs/references/constants'
import type { ApiReferenceMethod } from '@/lib/docs/references/types'
import { ApiMethodHeaderActions } from '@/components/global/api-explorer/ApiMethodHeaderActions'

function buildMethodLink(methodId: string): string {
  return `${window.location.origin}${window.location.pathname}${window.location.search}#${methodId}`
}

type ApiReferenceMethodActionsProps = {
  method: ApiReferenceMethod
  version: ReferenceVersion
  platform: ReferencePlatform
  className?: string
}

export function ApiReferenceMethodActions({
  method,
  version,
  platform,
  className,
}: ApiReferenceMethodActionsProps) {
  const getPageUrl = useCallback(
    () => buildMethodLink(method.id),
    [method.id],
  )

  const getMethodMarkdown = useCallback(
    (pageUrl: string) =>
      buildApiReferenceMethodMarkdown(method, version, platform, {
        pageUrl,
      }),
    [method, platform, version],
  )

  return (
    <ApiMethodHeaderActions
      className={className}
      getPageUrl={getPageUrl}
      getMethodMarkdown={getMethodMarkdown}
    />
  )
}
