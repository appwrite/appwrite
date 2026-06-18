import { Badge } from '@/components/ui/badge'
import { getMethodAuthKeys } from '@/lib/api-explorer/auth'
import { MethodDescriptionMarkdown } from '@/components/global/api-explorer/MethodDescriptionMarkdown'
import { DOCS_CONTAINER } from '@/lib/docs/docs-container'
import type { ApiReferenceMethod } from '@/lib/docs/references/types'
import { cn, truncateMiddle } from '@/lib/utils'

const ENDPOINT_URL_DISPLAY_MAX = 72

function splitMetadataList(value?: string): string[] {
  if (!value?.trim()) return []
  return value
    .split(',')
    .map((item) => item.trim())
    .filter(Boolean)
}

type ApiReferenceMethodDetailsProps = {
  method: ApiReferenceMethod
  baseUrl?: string
}

export function ApiReferenceMethodDetails({
  method,
  baseUrl = 'https://<REGION>.cloud.appwrite.io/v1',
}: ApiReferenceMethodDetailsProps) {
  const fullUrl = `${baseUrl.replace(/\/$/, '')}${method.path}`
  const scopes = splitMetadataList(method.scope)
  const authMethods = getMethodAuthKeys(method)
  const rateLimit = method.xAppwrite?.['rate-limit']
  const hasMetadata =
    scopes.length > 0 ||
    authMethods.length > 0 ||
    (rateLimit !== undefined && rateLimit > 0)

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="space-y-4 px-6 py-4">
        <div className="space-y-2">
          <p className="text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
            Endpoint
          </p>
          <div className="overflow-hidden rounded-lg border border-border bg-muted/30 px-3 py-2.5">
            <p className="flex min-w-0 items-center gap-2 font-mono text-[12px] leading-relaxed text-foreground">
              <span
                className={cn(
                  'shrink-0 font-semibold uppercase',
                  method.httpMethod === 'get' && 'text-blue-600 dark:text-blue-400',
                  method.httpMethod === 'post' &&
                    'text-emerald-600 dark:text-emerald-400',
                  (method.httpMethod === 'put' || method.httpMethod === 'patch') &&
                    'text-amber-600 dark:text-amber-400',
                  method.httpMethod === 'delete' &&
                    'text-red-600 dark:text-red-400',
                )}
              >
                {method.httpMethod}
              </span>
              <span className="min-w-0 flex-1 truncate" title={fullUrl}>
                {truncateMiddle(fullUrl, ENDPOINT_URL_DISPLAY_MAX)}
              </span>
            </p>
          </div>
        </div>

        {method.description ? (
          <div className="space-y-2">
            <p className="text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
              Description
            </p>
            <div className={DOCS_CONTAINER}>
              <MethodDescriptionMarkdown
                content={method.description}
                variant="docs"
              />
            </div>
          </div>
        ) : null}
      </div>

      {hasMetadata ? (
        <>
          <div className="border-t border-border" />
          <div className="grid gap-4 px-6 py-4 sm:grid-cols-2">
            {scopes.length > 0 ? (
              <div className="space-y-2 sm:col-span-2">
                <p className="text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
                  Required scopes
                </p>
                <div className="flex flex-wrap gap-1.5">
                  {scopes.map((scope) => (
                    <Badge
                      key={scope}
                      variant="info"
                      className="text-[10px] shrink-0 font-mono"
                    >
                      {scope}
                    </Badge>
                  ))}
                </div>
              </div>
            ) : null}

            {authMethods.length > 0 ? (
              <div className="space-y-2">
                <p className="text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
                  Required auth
                </p>
                <div className="flex flex-wrap gap-1.5">
                  {authMethods.map((auth) => (
                    <Badge
                      key={auth}
                      variant="inactive"
                      className="text-[10px] shrink-0"
                    >
                      {auth}
                    </Badge>
                  ))}
                </div>
              </div>
            ) : null}

            {rateLimit !== undefined && rateLimit > 0 ? (
              <div className="space-y-2">
                <p className="text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
                  Rate limit
                </p>
                <p className="text-[13px] text-foreground">
                  {rateLimit} requests per {method.xAppwrite?.['rate-time'] ?? 3600}s
                </p>
              </div>
            ) : null}
          </div>
        </>
      ) : null}
    </div>
  )
}
