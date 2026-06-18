import { Badge } from '@/components/ui/badge'
import { AuthRequirementDescription } from '@/components/global/shared/AuthRequirementDescription'
import { RateLimitDescription } from '@/components/global/shared/RateLimitDescription'
import { MethodDescriptionMarkdown } from '@/components/global/api-explorer/MethodDescriptionMarkdown'
import { DOCS_CONTAINER } from '@/lib/docs/docs-container'
import type { ReferencePlatform } from '@/lib/docs/references/constants'
import type { ApiReferenceMethod } from '@/lib/docs/references/types'
import { truncateMiddle, cn } from '@/lib/utils'
import {
  getHttpMethodAccentClasses,
  getHttpMethodVariant,
  REFERENCE_PILL_CLASS,
} from './explorer-styles'

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
  platform: ReferencePlatform
  baseUrl?: string
}

export function ApiReferenceMethodDetails({
  method,
  platform,
  baseUrl = 'https://<REGION>.cloud.appwrite.io/v1',
}: ApiReferenceMethodDetailsProps) {
  const fullUrl = `${baseUrl.replace(/\/$/, '')}${method.path}`
  const scopes = splitMetadataList(method.scope)
  const rateLimit = method.xAppwrite?.['rate-limit']
  const hasAuth = Boolean(
    method.xAppwrite?.auth && Object.keys(method.xAppwrite.auth).length > 0,
  ) || Boolean(method.security?.length)
  const hasMetadata =
    scopes.length > 0 || hasAuth || (rateLimit !== undefined && rateLimit > 0)
  const methodAccent = getHttpMethodAccentClasses(method.httpMethod)

  return (
    <div className="overflow-hidden rounded-xl border border-border bg-card/50">
      <div className="space-y-4 px-6 py-4">
        <div className="space-y-2">
          <p className="text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
            Endpoint
          </p>
          <div
            className={cn(
              'overflow-hidden rounded-lg px-3 py-2.5',
              methodAccent.endpointBox,
            )}
          >
            <p className="flex min-w-0 items-center gap-2.5 font-mono text-[12px] leading-relaxed text-foreground">
              <Badge
                variant={getHttpMethodVariant(method.httpMethod)}
                className={cn(
                  'shrink-0 font-mono text-[10px] uppercase',
                  REFERENCE_PILL_CLASS,
                )}
              >
                {method.httpMethod}
              </Badge>
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
                      className={cn(
                        'text-[10px] shrink-0 font-mono',
                        REFERENCE_PILL_CLASS,
                      )}
                    >
                      {scope}
                    </Badge>
                  ))}
                </div>
              </div>
            ) : null}

            {hasAuth ? (
              <div className="space-y-2 sm:col-span-2">
                <p className="text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
                  Authentication
                </p>
                <AuthRequirementDescription method={method} platform={platform} />
              </div>
            ) : null}

            {rateLimit !== undefined && rateLimit > 0 ? (
              <div className="space-y-2">
                <p className="text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
                  Rate limit
                </p>
                <RateLimitDescription
                  limit={rateLimit}
                  windowSeconds={method.xAppwrite?.['rate-time'] ?? 3600}
                  rateKey={method.xAppwrite?.['rate-key']}
                />
              </div>
            ) : null}
          </div>
        </>
      ) : null}
    </div>
  )
}
