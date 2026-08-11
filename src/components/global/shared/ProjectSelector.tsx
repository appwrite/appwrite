/**
 * Project Selector
 *
 * A searchable dropdown for selecting a project from an organization.
 * Keeps previous results visible while searching and shows a loading spinner
 * next to the search input.
 *
 * Reusable across org API keys, settings, and other flows.
 */

import { useState, useEffect, useMemo } from 'react'
import { useNavigate } from '@tanstack/react-router'
import { SearchableSelect } from '@/components/global/shared/SearchableSelect'
import {
  activeProjectsQueryOptions,
  organizationProjectScopeQueryOptions,
} from '@/lib/react-query/hooks'
import { formatProjectNameForDisplay } from '@/lib/react-query/hooks/projects'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useT } from '@/lib/i18n/translate'
import type { Models } from '@appwrite.io/console'

const DEFAULT_PROJECT_LIMIT = 15

export interface ProjectSelectorProps {
  /** Organization/team ID to fetch projects for */
  orgTeamId: string | null
  /** Called when a project is selected (used when getProjectLink is not provided) */
  onSelectProject?: (projectId: string) => void
  /** When provided, navigates on select (avoids layout shift vs full page reload) */
  getProjectLink?: (projectId: string) => {
    to: string
    params: Record<string, string>
  }
  /** Placeholder for the trigger button */
  placeholder?: string
  /** Max projects to fetch per request */
  limit?: number
  /** Whether to show API keys count next to each project */
  showApiKeysCount?: boolean
  /** Custom class for the trigger button */
  triggerClassName?: string
  /** Custom class for the popover content */
  contentClassName?: string
  disabled?: boolean
}

export function ProjectSelector({
  orgTeamId,
  onSelectProject,
  getProjectLink,
  placeholder = 'Select project',
  limit = DEFAULT_PROJECT_LIMIT,
  showApiKeysCount = false,
  triggerClassName,
  contentClassName,
  disabled = false,
}: ProjectSelectorProps) {
  const t = useT()
  const navigate = useNavigate()
  const [open, setOpen] = useState(false)
  const [search, setSearch] = useState('')
  const [debouncedSearch, setDebouncedSearch] = useState('')

  useEffect(() => {
    const timer = setTimeout(() => setDebouncedSearch(search), 300)
    return () => clearTimeout(timer)
  }, [search])

  useEffect(() => {
    if (!open) setSearch('')
  }, [open])

  // Restrict the list to what a project-scoped member can actually open.
  const { data: projectScope } = useQuery(
    organizationProjectScopeQueryOptions(orgTeamId),
  )

  const { data, isFetching } = useQuery({
    ...activeProjectsQueryOptions(
      orgTeamId,
      0,
      limit,
      debouncedSearch,
      undefined,
      projectScope ?? null,
    ),
    enabled: !!orgTeamId && open,
    placeholderData: keepPreviousData,
  })

  const items = useMemo(() => {
    const list = data?.projects ?? []
    return list.map((project: Models.Project) => {
      const name = formatProjectNameForDisplay(project.name)
      const paused = project.status === 'paused'
      const apiKeysCount = 0

      return {
        value: project.$id,
        label: paused ? `${name} ${t('(Paused)')}` : name,
        searchText: project.name,
        description:
          showApiKeysCount && apiKeysCount > 0
            ? `${apiKeysCount} API key${apiKeysCount === 1 ? '' : 's'}`
            : undefined,
      }
    })
  }, [data?.projects, showApiKeysCount, t])

  const handleSelectProject = (projectId: string) => {
    const link = getProjectLink?.(projectId)
    if (link) {
      navigate({ to: link.to, params: link.params })
      return
    }
    onSelectProject?.(projectId)
  }

  return (
    <SearchableSelect
      value=""
      onValueChange={handleSelectProject}
      items={items}
      placeholder={t(placeholder)}
      searchPlaceholder={t('Search projects...')}
      emptyMessage={isFetching ? '' : t('No projects found')}
      disabled={disabled || !orgTeamId}
      triggerClassName={triggerClassName}
      contentClassName={contentClassName}
      onSearchChange={setSearch}
      isFetching={isFetching}
      onOpenChange={setOpen}
      showPlaceholderWhenEmpty
    />
  )
}
