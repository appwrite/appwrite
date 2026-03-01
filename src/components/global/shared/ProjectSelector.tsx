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
import { Link } from '@tanstack/react-router'
import { ChevronRight, Loader2 } from '@/lib/icons'
import { Button } from '@/components/ui/button'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandList,
} from '@/components/ui/command'
import { activeProjectsQueryOptions } from '@/lib/react-query/hooks'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import { cn } from '@/lib/utils'

const DEFAULT_PROJECT_LIMIT = 15

export interface ProjectSelectorProps {
  /** Organization/team ID to fetch projects for */
  orgTeamId: string | null
  /** Called when a project is selected (used when getProjectLink is not provided) */
  onSelectProject?: (projectId: string) => void
  /** When provided, items render as links for client-side navigation (avoids layout shift) */
  getProjectLink?: (projectId: string) => { to: string; params: Record<string, string> }
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
}: ProjectSelectorProps) {
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

  const { data, isFetching } = useQuery({
    ...activeProjectsQueryOptions(
      orgTeamId,
      0,
      limit,
      debouncedSearch,
    ),
    enabled: !!orgTeamId && open,
    placeholderData: keepPreviousData,
  })

  const projects = useMemo(() => {
    const list = data?.projects ?? []
    return list.map((p: Models.Project) => {
      const keys = p.keys || []
      const apiKeysCount = Array.isArray(keys) ? keys.length : 0
      return { $id: p.$id, name: p.name, apiKeysCount }
    })
  }, [data?.projects])

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button
          variant="outline"
          className={cn(
            'h-9 w-full justify-between text-[13px] font-normal',
            triggerClassName,
          )}
        >
          <span className="text-muted-foreground">{placeholder}</span>
          <ChevronRight className="h-3.5 w-3.5 shrink-0 -rotate-90 opacity-50" />
        </Button>
      </PopoverTrigger>
      <PopoverContent
        className={cn(
          'min-w-[240px] w-[var(--radix-popover-trigger-width)] max-w-[320px] p-0',
          contentClassName,
        )}
        align="start"
      >
        <Command shouldFilter={false}>
          <div className="relative">
            <CommandInput
              placeholder="Search projects..."
              value={search}
              onValueChange={setSearch}
              className={cn('h-9', isFetching && 'pr-8')}
            />
            <div
              className={cn(
                'pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 transition-opacity duration-200',
                isFetching ? 'opacity-100' : 'opacity-0',
              )}
              aria-hidden
            >
              <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />
            </div>
          </div>
          <CommandList className="max-h-[240px]">
            {projects.length === 0 && (
              <CommandEmpty>
                {isFetching ? '' : 'No projects found'}
              </CommandEmpty>
            )}
            <CommandGroup>
              {projects.map((p) => {
                const content = (
                  <>
                    <span className="truncate">{p.name}</span>
                    {showApiKeysCount && p.apiKeysCount > 0 && (
                      <span className="ml-1.5 shrink-0 text-muted-foreground">
                        ({p.apiKeysCount})
                      </span>
                    )}
                  </>
                )
                const itemClassName =
                  'flex w-full cursor-pointer items-center rounded-sm px-2 py-1.5 text-left text-[13px] outline-none transition-colors hover:bg-accent hover:text-accent-foreground'

                if (getProjectLink) {
                  const link = getProjectLink(p.$id)
                  return (
                    <Link
                      key={p.$id}
                      to={link.to}
                      params={link.params}
                      onClick={() => setOpen(false)}
                      className={itemClassName}
                    >
                      {content}
                    </Link>
                  )
                }
                return (
                  <button
                    key={p.$id}
                    type="button"
                    onClick={() => {
                      onSelectProject?.(p.$id)
                      setOpen(false)
                    }}
                    className={itemClassName}
                  >
                    {content}
                  </button>
                )
              })}
            </CommandGroup>
          </CommandList>
        </Command>
      </PopoverContent>
    </Popover>
  )
}
