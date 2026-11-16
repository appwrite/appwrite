import { Link } from '@tanstack/react-router'
import type { ProjectAuthMethodId } from '@appwrite.io/console'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  getOAuth2ProviderDisplayName,
  getOAuth2ProviderIconPath,
} from '@/lib/oauth2/provider-display'
import { PUBLIC_CATALOG_LOGO_IMAGE_CLASSES } from '@/lib/public-icon-classes'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import { useVisibleAuthMethods } from '../Settings'

export function AuthMethodsCard({
  projectId,
  methods,
  providerIds,
  canManage,
}: {
  projectId: string
  methods: Record<ProjectAuthMethodId, boolean>
  /** Enabled social providers; undefined when the viewer cannot read them. */
  providerIds: string[] | undefined
  canManage: boolean
}) {
  const t = useT()
  const authMethods = useVisibleAuthMethods()
  const enabledCount = authMethods.filter(
    (method) => methods[method.key],
  ).length

  return (
    <div className="overflow-hidden rounded-xl border border-border bg-card/50">
      <div className="flex h-16 items-center px-6">
        <div className="min-w-0">
          <h3 className="text-[15px] font-semibold leading-5 text-foreground">
            {t('Auth methods')}
          </h3>
          <p className="h-4 truncate text-[12px] leading-4 text-muted-foreground">
            {t('{enabled} of {total} enabled')
              .replace('{enabled}', enabledCount.toLocaleString())
              .replace('{total}', authMethods.length.toLocaleString())}
          </p>
        </div>
      </div>
      <div className="border-t border-border" />
      <div className="space-y-5 px-6 py-5">
        <div className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-4">
          {authMethods.map((method) => {
            const Icon = method.icon
            const enabled = methods[method.key]
            return (
              <div
                key={method.key}
                className="flex h-[52px] min-w-0 items-center justify-between gap-2 rounded-lg border border-border/60 bg-background/40 px-3"
              >
                <div className="flex min-w-0 items-center gap-2">
                  <Icon className="h-4 w-4 shrink-0 text-muted-foreground" />
                  <span className="truncate text-[13px] font-medium text-foreground">
                    {t(method.label)}
                  </span>
                </div>
                <Badge
                  variant={enabled ? 'success' : 'inactive'}
                  className="shrink-0 text-[10px]"
                >
                  {enabled ? t('Enabled') : t('Disabled')}
                </Badge>
              </div>
            )
          })}
        </div>

        {providerIds ? (
          <div>
            <p className="mb-2 text-[12px] text-muted-foreground">
              {t('Social providers')}
            </p>
            {providerIds.length > 0 ? (
              <div className="flex flex-wrap gap-2">
                {providerIds.map((providerId) => (
                  <span
                    key={providerId}
                    className="inline-flex h-8 items-center gap-2 rounded-lg border border-border/60 bg-background/40 px-3 text-[13px] font-medium text-foreground"
                  >
                    <img
                      src={getOAuth2ProviderIconPath(providerId)}
                      alt=""
                      decoding="async"
                      className={cn(
                        PUBLIC_CATALOG_LOGO_IMAGE_CLASSES,
                        'h-4 w-4 shrink-0',
                      )}
                    />
                    {getOAuth2ProviderDisplayName(providerId)}
                  </span>
                ))}
              </div>
            ) : (
              <p className="text-[13px] text-foreground">
                {t('No social providers enabled')}
              </p>
            )}
          </div>
        ) : null}
      </div>
      {canManage ? (
        <div className="flex h-[68px] items-center justify-end gap-2 border-t border-border bg-muted/30 px-6">
          <Button
            variant="outline"
            size="sm"
            className="h-9 text-[13px]"
            asChild
          >
            <Link
              to="/projects/$projectId/auth/social-providers"
              params={{ projectId }}
            >
              {t('Configure providers')}
            </Link>
          </Button>
          <Button
            variant="secondary"
            size="sm"
            className="h-9 text-[13px]"
            asChild
          >
            <Link
              to="/projects/$projectId/auth/settings"
              params={{ projectId }}
            >
              {t('Configure methods')}
            </Link>
          </Button>
        </div>
      ) : null}
    </div>
  )
}
