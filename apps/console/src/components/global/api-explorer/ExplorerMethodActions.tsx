'use client'

import { useCallback } from 'react'
import { buildApiReferenceMethodMarkdown } from '@/lib/docs/references/method-markdown'
import { LATEST_EXAMPLES_VERSION } from '@/lib/docs/references/constants'
import type { ReferencePlatform } from '@/lib/docs/references/constants'
import type { ApiReferenceMethod } from '@/lib/docs/references/types'
import type {
  ApiExplorerMethod,
  ApiExplorerProjectPlatform,
} from '@/lib/api-explorer/types'
import { getExplorerMethodLinkUrl } from '@/lib/api-explorer/get-explorer-method-route'
import { ApiMethodHeaderActions } from './ApiMethodHeaderActions'

function toReferencePlatform(
  platform: ApiExplorerProjectPlatform,
): ReferencePlatform {
  return platform === 'client' ? 'client-rest' : 'server-rest'
}

type ExplorerMethodActionsProps = {
  method: ApiExplorerMethod
  endpoint: string
  platform: ApiExplorerProjectPlatform
  projectId: string
  serviceId: string
  className?: string
}

export function ExplorerMethodActions({
  method,
  endpoint,
  platform,
  projectId,
  serviceId,
  className,
}: ExplorerMethodActionsProps) {
  const referencePlatform = toReferencePlatform(platform)

  const getPageUrl = useCallback(
    () =>
      getExplorerMethodLinkUrl({
        projectId,
        serviceId,
        operationId: method.operationId,
      }),
    [method.operationId, projectId, serviceId],
  )

  const getMethodMarkdown = useCallback(
    (pageUrl: string) => {
      const methodForMarkdown: ApiReferenceMethod = {
        ...method,
        responses: [],
      }

      return buildApiReferenceMethodMarkdown(
        methodForMarkdown,
        LATEST_EXAMPLES_VERSION,
        referencePlatform,
        {
          baseUrl: endpoint,
          pageUrl,
        },
      )
    },
    [endpoint, method, referencePlatform],
  )

  return (
    <ApiMethodHeaderActions
      className={className}
      getPageUrl={getPageUrl}
      getMethodMarkdown={getMethodMarkdown}
    />
  )
}
