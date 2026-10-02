import { useParams } from '@tanstack/react-router'
import { OAuth2AppKeysCard } from '@/components/global/shared/OAuth2AppKeysCard'
import {
  useCreateOrganizationAppKey,
  useDeleteOrganizationAppKey,
  useOrganizationApp,
  useOrganizationAppKeys,
} from '@/lib/react-query/hooks'

export function View() {
  const { appId } = useParams({ strict: false })
  const { app } = useOrganizationApp(appId)
  const { keys, isLoading, hasMore, loadMore, isLoadingMore } =
    useOrganizationAppKeys(appId)
  const createMutation = useCreateOrganizationAppKey(appId)
  const deleteMutation = useDeleteOrganizationAppKey(appId)

  if (!app) return null

  return (
    <OAuth2AppKeysCard
      keys={keys}
      isLoading={isLoading}
      hasMore={hasMore}
      onLoadMore={loadMore}
      isLoadingMore={isLoadingMore}
      onCreate={() => createMutation.mutateAsync()}
      onDelete={(keyId) => deleteMutation.mutateAsync(keyId)}
      isCreating={createMutation.isPending}
      isDeleting={deleteMutation.isPending}
    />
  )
}
