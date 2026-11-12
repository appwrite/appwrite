import { usePostgresDatabaseSettingsPage } from './usePostgresDatabaseSettingsPage'
import { PostgresSettingsLoading } from './PostgresSettingsLoading'
import { PostgresExtensionsPanel } from '../_components/PostgresExtensionsPanel'

export function View() {
  const { databaseId, isLoading } = usePostgresDatabaseSettingsPage()

  if (isLoading) return <PostgresSettingsLoading />

  return <PostgresExtensionsPanel databaseId={databaseId} />
}
