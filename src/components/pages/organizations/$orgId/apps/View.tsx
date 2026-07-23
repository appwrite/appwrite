import { useMemo, useState } from 'react'
import { useNavigate, useParams } from '@tanstack/react-router'
import { Loader2, Package, Plus } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { EmptyState } from '@/components/global/shared/EmptyState'
import {
  useCreateOrganizationApp,
  useOrganizationApps,
  useOrganizations,
} from '@/lib/react-query/hooks'
import { MarketplaceAppCard } from '../marketplace/_components/MarketplaceAppCard'
import { CreateMarketplaceApp } from '../marketplace/_components/CreateMarketplaceApp'
import type { CreateMarketplaceAppInput } from '../marketplace/_components/CreateMarketplaceApp'
import type { MarketplaceApp } from '@/lib/marketplace/types'
import { toast } from 'sonner'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT } from '@/lib/i18n/translate'
import { analyticsAttrs } from '@/lib/analytics-actions'

export function View() {
  const t = useT()
  const { orgId } = useParams({ strict: false })
  const navigate = useNavigate()
  const { organizations } = useOrganizations()

  const teamNamesById = useMemo(
    () =>
      Object.fromEntries(
        organizations.map((org) => [org.$id, org.name] as const),
      ),
    [organizations],
  )

  const {
    apps,
    isLoading,
    isFetching,
  } = useOrganizationApps(orgId, teamNamesById)

  const createAppMutation = useCreateOrganizationApp(orgId)
  const [createDialogOpen, setCreateDialogOpen] = useState(false)

  const openApp = (app: MarketplaceApp) => {
    if (!orgId) return
    navigate({
      to: '/organizations/$orgId/apps/$appId',
      params: { orgId, appId: app.$id },
    })
  }

  const handleCreateApp = async (input: CreateMarketplaceAppInput) => {
    try {
      const app = await createAppMutation.mutateAsync(input)
      setCreateDialogOpen(false)
      toast.success(t('App created as draft'))
      if (orgId && app?.$id) {
        navigate({
          to: '/organizations/$orgId/apps/$appId',
          params: { orgId, appId: app.$id },
        })
      }
    } catch (error) {
      toast.error(getErrorMessage(error, t('Failed to create app')))
    }
  }

  if (isLoading && apps.length === 0) {
    return (
      <div className="flex min-h-64 items-center justify-center">
        <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
      </div>
    )
  }

  return (
    <div>
      <div className="flex items-center justify-between gap-4 mb-6">
        <div>
          <h2 className="text-[15px] font-semibold text-foreground">{t('Apps')}</h2>
          <p className="text-[13px] text-muted-foreground mt-1">
            {t('OAuth2 apps published by your organization to the marketplace.')}
          </p>
        </div>
        <Button
          size="sm"
          onClick={() => setCreateDialogOpen(true)}
          {...analyticsAttrs('create-marketplace-app')}
        >
          <Plus className="me-1.5 h-3.5 w-3.5" />
          {t('Add app')}
        </Button>
      </div>

      {apps.length === 0 ? (
        <EmptyState
          icon={Package}
          title={t('No apps yet')}
          description={t('Create an app to share it with other organizations on the marketplace.')}
          action={
            <Button
              size="sm"
              onClick={() => setCreateDialogOpen(true)}
              {...analyticsAttrs('create-marketplace-app')}
            >
              {t('Add app')}
            </Button>
          }
          variant="card"
        />
      ) : (
        <div className="relative">
          {isFetching && (
            <div className="absolute end-0 top-0 z-10">
              <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />
            </div>
          )}
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
            {apps.map((app) => (
              <MarketplaceAppCard
                key={app.$id}
                app={app}
                onClick={() => openApp(app)}
              />
            ))}
          </div>
        </div>
      )}

      <CreateMarketplaceApp
        open={createDialogOpen}
        onOpenChange={setCreateDialogOpen}
        onCreate={handleCreateApp}
        isSubmitting={createAppMutation.isPending}
      />
    </div>
  )
}
