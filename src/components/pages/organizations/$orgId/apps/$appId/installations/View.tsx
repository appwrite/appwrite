import { useParams } from '@tanstack/react-router'
import { OAuth2AppInstallationsCard } from '@/components/global/shared/OAuth2AppInstallationsCard'
import {
  useDeleteOrganizationAppInstallation,
  useOrganizationApp,
  useOrganizationAppInstallations,
} from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'

export function View() {
  const t = useT()
  const { appId } = useParams({ strict: false })
  const { app } = useOrganizationApp(appId)
  const { installations, isLoading, hasMore, loadMore, isLoadingMore } =
    useOrganizationAppInstallations(appId)
  const deleteMutation = useDeleteOrganizationAppInstallation(appId)

  if (!app) return null

  return (
    <OAuth2AppInstallationsCard
      installations={installations}
      isLoading={isLoading}
      hasMore={hasMore}
      onLoadMore={loadMore}
      isLoadingMore={isLoadingMore}
      onDelete={(installationId) => deleteMutation.mutateAsync(installationId)}
      isDeleting={deleteMutation.isPending}
      teamLabel={t('Organization')}
    />
  )
}
