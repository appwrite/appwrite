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
import { organizationProjectScopeQueryOptions } from '@/lib/react-query/hooks'
import {
  formatProjectNameForDisplay,
  useProject,
  useProjectsForTeamInfinite,
} from '@/lib/react-query/hooks/projects'
import { useQuery } from '@tanstack/react-query'
import { useT } from '@/lib/i18n/translate'

const DEFAULT_PROJECT_LIMIT = 15

export interface ProjectSelectorProps {
  /** Organization/team ID to fetch projects for */
  orgTeamId: string | null
  /**
   * Selected project id, for the controlled case. Omit where selecting is an
   * action rather than a value (navigating, or handing the id to a callback):
   * the trigger then keeps showing the placeholder.
   */
  value?: string
  /** Project ids to leave out, e.g. ones already chosen by a sibling selector. */
  excludeProjectIds?: string[]
  /**
   * Show the project id beneath each name. Project names are not unique, so
   * anywhere the list can contain duplicates this is the only way to tell them
   * apart.
   */
  showProjectId?: boolean
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
  /** Custom class for the popover's scroll area (e.g. `min-h-0` for short lists) */
  listClassName?: string
  disabled?: boolean
}

export function ProjectSelector({
  orgTeamId,
  value = '',
  excludeProjectIds,
  showProjectId = false,
  onSelectProject,
  getProjectLink,
  placeholder = 'Select project',
  limit = DEFAULT_PROJECT_LIMIT,
  showApiKeysCount = false,
  triggerClassName,
  contentClassName,
  listClassName,
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

  const { data: projectScope } = useQuery(
    organizationProjectScopeQueryOptions(orgTeamId),
  )

  // Infinite rather than a single page: an organization can hold far more
  // projects than one page, and the droplist previously just stopped at the
  // first `limit` with no way to reach the rest.
  const {
    projects,
    isFetching,
    isFetchingNextPage,
    hasNextPage,
    fetchNextPage,
  } = useProjectsForTeamInfinite(
    open ? orgTeamId : null,
    limit,
    debouncedSearch || undefined,
    excludeProjectIds,
    projectScope ?? null,
  )

  // The selected project may sit outside the current page or search, so it is
  // resolved separately; without it the trigger falls back to the placeholder
  // and a controlled selection looks empty.
  const { project: selectedProject } = useProject(value || undefined)

  const items = useMemo(() => {
    const mapped = projects.map((project) => {
      const name = formatProjectNameForDisplay(project.name)
      const paused = project.paused === true
      const apiKeysCount = 0

      return {
        value: project.$id,
        label: paused ? `${name} ${t('(Paused)')}` : name,
        // Includes the id so the value stays unique: names are not, and cmdk
        // keys rows by it, so duplicates would highlight and navigate as one.
        searchText: `${project.name} ${project.$id}`,
        description:
          showApiKeysCount && apiKeysCount > 0
            ? `${apiKeysCount} API key${apiKeysCount === 1 ? '' : 's'}`
            : showProjectId
              ? project.$id
              : undefined,
      }
    })

    if (value && !mapped.some((item) => item.value === value)) {
      const name = selectedProject?.name
        ? formatProjectNameForDisplay(selectedProject.name)
        : value
      mapped.unshift({
        value,
        label: name,
        searchText: `${name} ${value}`,
        description: showProjectId ? value : undefined,
      })
    }

    return mapped
  }, [projects, showApiKeysCount, showProjectId, t, value, selectedProject])

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
      value={value}
      onValueChange={handleSelectProject}
      items={items}
      placeholder={t(placeholder)}
      searchPlaceholder={t('Search projects...')}
      emptyMessage={isFetching ? '' : t('No projects found')}
      disabled={disabled || !orgTeamId}
      triggerClassName={triggerClassName}
      contentClassName={contentClassName}
      listClassName={listClassName}
      onSearchChange={setSearch}
      isFetching={isFetching}
      hasNextPage={hasNextPage}
      isFetchingNextPage={isFetchingNextPage}
      onLoadMore={fetchNextPage}
      onOpenChange={setOpen}
      showPlaceholderWhenEmpty
    />
  )
}
