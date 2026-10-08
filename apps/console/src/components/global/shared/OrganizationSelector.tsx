/**
 * Organization Selector
 *
 * Searchable dropdown for choosing an organization. Uses the same
 * SearchableSelect pattern as ProjectSelector for consistent styling.
 */

import { useMemo } from 'react'
import { SearchableSelect } from '@/components/global/shared/SearchableSelect'
import { useTeams } from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'

type OrganizationOption = {
  $id: string
  name: string
}

export interface OrganizationSelectorProps {
  value: string | null
  onValueChange: (orgId: string) => void
  /** When omitted, organizations are loaded from useTeams(). */
  organizations?: OrganizationOption[]
  isLoading?: boolean
  placeholder?: string
  disabled?: boolean
  triggerClassName?: string
  contentClassName?: string
}

export function OrganizationSelector({
  value,
  onValueChange,
  organizations: organizationsProp,
  isLoading: isLoadingProp,
  placeholder = 'Select organization',
  disabled = false,
  triggerClassName,
  contentClassName,
}: OrganizationSelectorProps) {
  const t = useT()
  const { organizations: teamsOrganizations, isLoading: orgsLoading } =
    useTeams()
  const organizations = organizationsProp ?? teamsOrganizations
  const isLoading = isLoadingProp ?? orgsLoading

  const items = useMemo(
    () =>
      organizations.map((organization) => ({
        value: organization.$id,
        label: organization.name,
      })),
    [organizations],
  )

  return (
    <SearchableSelect
      value={value ?? ''}
      onValueChange={onValueChange}
      items={items}
      placeholder={isLoading ? t('Loading…') : t(placeholder)}
      searchPlaceholder={t('Search organizations...')}
      emptyMessage={t('No organizations found')}
      disabled={disabled || isLoading}
      triggerClassName={triggerClassName}
      contentClassName={contentClassName}
    />
  )
}
