'use client'

import {
  getMethodAuthDescription,
  IMPERSONATION_DOCS_HREF,
  type MethodAuthDescription as MethodAuthDescriptionData,
} from '@/lib/api-explorer/format-method-auth'
import type { ApiExplorerMethod } from '@/lib/api-explorer/types'
import { cn } from '@/lib/utils'
import { DocsRouteLink } from '@/components/pages/docs/DocsRouteLink'
import { MetadataTextMarkdown } from '@/components/global/shared/MetadataTextMarkdown'
import { useT } from '@/lib/i18n/translate'

type AuthRequirementDescriptionProps = {
  method: ApiExplorerMethod
  platform?: string
  className?: string
}

export function AuthRequirementDescription({
  method,
  platform,
  className,
}: AuthRequirementDescriptionProps) {
  const description = getMethodAuthDescription(method, platform)

  return (
    <AuthRequirementDescriptionContent
      description={description}
      className={className}
    />
  )
}

const SECONDARY_TEXT_CLASS =
  'text-[12px] leading-relaxed text-muted-foreground'

export function AuthRequirementDescriptionContent({
  description,
  className,
}: {
  description: MethodAuthDescriptionData
  className?: string
}) {
  const t = useT()
  return (
    <div className={cn('space-y-1.5', className)}>
      <MetadataTextMarkdown
        content={description.summary}
        className="text-[13px] leading-relaxed text-foreground"
      />

      {description.impersonation ? (
        <p className={SECONDARY_TEXT_CLASS}>
          {t('Supports optional impersonation.')}{' '}
          <DocsRouteLink
            href={IMPERSONATION_DOCS_HREF}
            className="font-medium text-foreground underline underline-offset-2 hover:text-primary"
          >
            {t('Learn more')}
          </DocsRouteLink>
        </p>
      ) : null}
    </div>
  )
}
