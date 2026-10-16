import { useEffect, useMemo, useRef } from 'react'
import { AlertCircle } from 'lucide-react'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import { ScrollArea } from '@/components/ui/scroll-area'
import { ConnectCodeExample } from '@/components/global/shared/ConnectCodeExample'
import type { CodeBlockLanguage } from '@/components/global/shared/CodeBlock'
import {
  RequestBuilderPanel,
  RequestBuilderSection,
} from '@/components/global/api-explorer/RequestFormFields'
import {
  getRequestBodyFormFields,
  parameterToFormField,
} from '@/lib/api-explorer/request-form'
import {
  PLATFORM_CODE_LANGUAGES,
  type ReferencePlatform,
  type ReferenceVersion,
} from '@/lib/docs/references/constants'
import type { ApiReferenceMethod } from '@/lib/docs/references/types'
import { cn } from '@/lib/utils'
import { ApiReferenceMethodDetails } from './ApiReferenceMethodDetails'
import { ApiReferenceMethodActions } from './ApiReferenceMethodActions'
import { ApiReferenceOpenInExplorer } from './ApiReferenceOpenInExplorer'
import {
  ApiReferenceParameterFields,
  ApiReferenceResponsesPanel,
} from './ApiReferenceParameterFields'
import { ApiReferenceCollapsibleCard } from './ApiReferenceCollapsibleCard'
import {
  getHttpMethodVariant,
  REFERENCE_COLUMN_HEADER_CLASS,
  REFERENCE_PILL_CLASS,
  REFERENCE_SCROLL_AREA_CLASS,
} from './explorer-styles'

function getDeprecatedWarningCopy(method: ApiReferenceMethod): {
  title: string
  description: string
} {
  const meta = method.xAppwrite?.deprecated
  const descriptionParts: string[] = []

  if (meta?.since) {
    descriptionParts.push(`Deprecated since ${meta.since}.`)
  }
  if (meta?.replaceWith) {
    descriptionParts.push(`Use ${meta.replaceWith} instead.`)
  }
  if (descriptionParts.length === 0) {
    descriptionParts.push(
      'This endpoint is deprecated and may be removed in a future version.',
    )
  }

  return {
    title: 'Deprecated endpoint',
    description: descriptionParts.join(' '),
  }
}

type ApiReferenceMethodPanelProps = {
  method?: ApiReferenceMethod
  serviceId: string
  version: ReferenceVersion
  platform: ReferencePlatform
}

export function ApiReferenceMethodPanel({
  method,
  serviceId,
  version,
  platform,
}: ApiReferenceMethodPanelProps) {
  const scrollViewportRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    scrollViewportRef.current?.scrollTo({ top: 0 })
  }, [method?.id])

  const pathFields = useMemo(
    () =>
      (method?.parameters ?? [])
        .filter((param) => param.in === 'path')
        .map((param) => parameterToFormField(param)),
    [method?.parameters],
  )
  const queryFields = useMemo(
    () =>
      (method?.parameters ?? [])
        .filter((param) => param.in === 'query')
        .map((param) => parameterToFormField(param)),
    [method?.parameters],
  )
  const bodyFields = useMemo(
    () => (method ? getRequestBodyFormFields(method) : []),
    [method],
  )

  const hasPathParams = pathFields.length > 0
  const hasQueryParams = queryFields.length > 0
  const hasRequestBody = bodyFields.length > 0
  const hasRequestSections = hasPathParams || hasQueryParams || hasRequestBody
  const codeLanguage = PLATFORM_CODE_LANGUAGES[platform]

  if (!method) {
    return (
      <div className="flex h-full min-h-0 min-w-0 flex-1 flex-col overflow-hidden">
        <div className={REFERENCE_COLUMN_HEADER_CLASS} aria-hidden />
        <div className="flex flex-1 items-center justify-center text-[13px] text-muted-foreground">
          Select a method to view its reference.
        </div>
      </div>
    )
  }

  const deprecatedCopy = method.deprecated
    ? getDeprecatedWarningCopy(method)
    : null

  return (
    <div className="flex h-full min-h-0 min-w-0 flex-1 flex-col overflow-hidden">
      <div
        className={cn(
          REFERENCE_COLUMN_HEADER_CLASS,
          'items-center gap-2.5',
        )}
      >
        <Badge
          variant={getHttpMethodVariant(method.httpMethod)}
          className={cn('shrink-0 text-[10px] uppercase', REFERENCE_PILL_CLASS)}
        >
          {method.httpMethod}
        </Badge>
        <p className="min-w-0 flex-1 truncate text-[13px] font-medium text-foreground">
          {method.summary}
        </p>
        <div className="flex shrink-0 items-center gap-1">
          <ApiReferenceOpenInExplorer
            serviceId={serviceId}
            operationId={method.operationId}
          />
          <ApiReferenceMethodActions
            method={method}
            version={version}
            platform={platform}
          />
        </div>
      </div>

      {deprecatedCopy ? (
        <div className="shrink-0 border-b border-border bg-amber-500/5 px-4 py-3">
          <Alert variant="default" className="border-amber-500/30 bg-transparent">
            <AlertCircle className="h-4 w-4 text-amber-500" />
            <AlertTitle className="text-[13px] font-medium text-amber-600 dark:text-amber-400">
              {deprecatedCopy.title}
            </AlertTitle>
            <AlertDescription className="text-[12px] text-amber-600/80 dark:text-amber-400/80">
              {deprecatedCopy.description}
            </AlertDescription>
          </Alert>
        </div>
      ) : null}

      <ScrollArea
        className={REFERENCE_SCROLL_AREA_CLASS}
        viewportRef={scrollViewportRef}
      >
        <div className="space-y-6 p-4 sm:p-6">
          <ApiReferenceMethodDetails method={method} platform={platform} />

          {hasRequestSections ? (
            <ApiReferenceCollapsibleCard title="Request" cardId="parameters">
              <RequestBuilderPanel className="rounded-none border-0">
                {hasPathParams ? (
                  <RequestBuilderSection title="Path">
                    <ApiReferenceParameterFields fields={pathFields} />
                  </RequestBuilderSection>
                ) : null}

                {hasQueryParams ? (
                  <RequestBuilderSection
                    title="Query parameters"
                    showTopBorder={hasPathParams}
                  >
                    <ApiReferenceParameterFields fields={queryFields} />
                  </RequestBuilderSection>
                ) : null}

                {hasRequestBody ? (
                  <RequestBuilderSection
                    title={hasPathParams || hasQueryParams ? 'Body' : 'Parameters'}
                    showTopBorder={hasPathParams || hasQueryParams}
                  >
                    <ApiReferenceParameterFields fields={bodyFields} />
                  </RequestBuilderSection>
                ) : null}
              </RequestBuilderPanel>
            </ApiReferenceCollapsibleCard>
          ) : null}

          <ApiReferenceResponsesPanel responses={method.responses} />

          {method.demo ? (
            <ConnectCodeExample
              code={method.demo}
              language={codeLanguage as CodeBlockLanguage}
            />
          ) : (
            <div className="rounded-lg border border-border bg-muted/20 px-4 py-6 text-[13px] text-muted-foreground">
              No code example available for this platform.
            </div>
          )}
        </div>
      </ScrollArea>
    </div>
  )
}
