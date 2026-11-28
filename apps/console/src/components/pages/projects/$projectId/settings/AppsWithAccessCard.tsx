import { useState } from 'react'
import { Link } from '@tanstack/react-router'
import type { Models } from '@appwrite.io/console'
import { Blocks, Loader2 } from 'lucide-react'
import { toast } from 'sonner'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { ConfirmActionDialog } from '@/components/global/shared/ConfirmActionDialog'
import { resolveAppLogoDisplayUrl } from '@/lib/appwrite/apps-logo'
import { mapAppToMarketplaceApp } from '@/lib/marketplace/map-app'
import {
  PROJECT_RAR_TYPE,
  WILDCARD_IDENTIFIER,
} from '@/lib/oauth2/authorization-details'
import {
  useOrganizationApp,
  useOrganizationScopes,
  useProjectInstalledApps,
  useUpdateOrganizationInstalledApp,
} from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT } from '@/lib/i18n/translate'
import { MarketplaceAppLogo } from '@/components/pages/organizations/$orgId/marketplace/_components/MarketplaceAppLogo'

/** Marketplace apps installed on the organization that can reach this project. */
export function AppsWithAccessCard({
  projectId,
  teamId,
}: {
  projectId: string
  teamId: string
}) {
  const t = useT()
  const { access } = useOrganizationScopes(teamId)
  const { installations, isLoading, isError, refetch } =
    useProjectInstalledApps(teamId, projectId)
  const updateMutation = useUpdateOrganizationInstalledApp(teamId)
  const [removeTarget, setRemoveTarget] =
    useState<Models.AppInstallation | null>(null)

  const handleRemove = async () => {
    if (!removeTarget) return
    // Drop only this project from the grant; other entries stay as they are.
    const details = removeTarget.authorizationDetails.flatMap((detail) => {
      if (detail.type !== PROJECT_RAR_TYPE) return [detail]
      const identifiers = (
        Array.isArray(detail.identifiers)
          ? (detail.identifiers as string[])
          : []
      ).filter((id) => id !== projectId)
      return identifiers.length > 0 ? [{ ...detail, identifiers }] : []
    })
    try {
      await updateMutation.mutateAsync({
        installationId: removeTarget.$id,
        authorizationDetails: JSON.stringify(details),
      })
      toast.success(t('Access removed'))
      setRemoveTarget(null)
    } catch (error) {
      toast.error(getErrorMessage(error, t('Failed to remove access')))
    }
  }

  return (
    <>
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="flex items-start justify-between gap-3 px-6 py-4">
          <div>
            <h3 className="text-[15px] font-semibold text-foreground">
              {t('Apps with access')}
            </h3>
            <p className="mt-2 text-[13px] text-muted-foreground">
              {t(
                'Marketplace apps installed on your organization that can reach this project.',
              )}
            </p>
          </div>
          <Button
            variant="outline"
            size="sm"
            className="h-8 text-[12px]"
            asChild
          >
            <Link
              to="/organizations/$orgId/settings/installations"
              params={{ orgId: teamId }}
            >
              {t('Manage')}
            </Link>
          </Button>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4">
          {isLoading ? (
            <div className="flex items-center justify-center py-6">
              <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
            </div>
          ) : isError ? (
            <div className="flex items-center justify-between gap-3">
              <p className="text-[13px] text-destructive">
                {t('Failed to load apps with access.')}
              </p>
              <Button
                type="button"
                variant="outline"
                size="sm"
                className="h-8 text-[12px]"
                onClick={() => void refetch()}
              >
                {t('Try again')}
              </Button>
            </div>
          ) : installations.length === 0 ? (
            <p className="text-[13px] text-muted-foreground">
              {t('No apps can access this project.')}
            </p>
          ) : (
            <div className="overflow-hidden rounded-lg border border-border divide-y divide-border">
              {installations.map((installation) => (
                <AppRow
                  key={installation.$id}
                  installation={installation}
                  organizationId={teamId}
                  canRemove={access.isOwner}
                  onRemove={() => setRemoveTarget(installation)}
                />
              ))}
            </div>
          )}
        </div>
      </div>

      <ConfirmActionDialog
        open={removeTarget !== null}
        onOpenChange={(open) => {
          if (!open) setRemoveTarget(null)
        }}
        title={t('Remove access')}
        description={t(
          'The app can no longer reach this project. It stays installed on the organization and keeps access to its other projects.',
        )}
        confirmLabel={t('Remove')}
        confirmVariant="destructive"
        onConfirm={() => void handleRemove()}
        isConfirming={updateMutation.isPending}
      />
    </>
  )
}

function AppRow({
  installation,
  organizationId,
  canRemove,
  onRemove,
}: {
  installation: Models.AppInstallation
  organizationId: string
  canRemove: boolean
  onRemove: () => void
}) {
  const t = useT()
  const { app } = useOrganizationApp(installation.appId)
  const mapped = app ? mapAppToMarketplaceApp(app, { organizationId }) : null
  const logoUrl = mapped
    ? resolveAppLogoDisplayUrl(mapped.logoUri, { width: 64, height: 64 })
    : null
  // A wildcard grant can only be narrowed from the organization's settings.
  const viaAllProjects = installation.authorizationDetails.some(
    (detail) =>
      detail.type === PROJECT_RAR_TYPE &&
      Array.isArray(detail.identifiers) &&
      detail.identifiers.includes(WILDCARD_IDENTIFIER),
  )

  return (
    <div className="flex items-center justify-between gap-3 p-3">
      <div className="flex min-w-0 items-center gap-3">
        {logoUrl ? (
          <MarketplaceAppLogo
            src={logoUrl}
            size="sm"
            monochrome={mapped?.isOfficial}
          />
        ) : (
          <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-muted">
            <Blocks className="h-4 w-4 text-muted-foreground" />
          </div>
        )}
        <span className="truncate text-[13px] font-medium text-foreground">
          {mapped?.name ?? installation.appId}
        </span>
        <Badge variant="inactive" className="text-[10px] shrink-0">
          {viaAllProjects ? t('All projects') : t('This project')}
        </Badge>
      </div>
      {canRemove && !viaAllProjects ? (
        <Button
          variant="outline"
          size="sm"
          className="h-8 text-[12px]"
          onClick={onRemove}
        >
          {t('Remove')}
        </Button>
      ) : null}
    </div>
  )
}
