'use client'

import { useEffect, useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Check, ChevronDown, FolderOpen, Loader2, X } from 'lucide-react'
import { InitialsAvatar } from '@/components/global/shared/Avatar'
import { Button } from '@/components/ui/button'
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
} from '@/components/ui/command'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Skeleton } from '@/components/ui/skeleton'
import { analyticsAttrs } from '@/lib/analytics-actions'
import {
  formatProjectNameForDisplay,
  organizationProjectScopeQueryOptions,
  organizationsQueryOptions,
  useProjectsForTeamInfinite,
} from '@/lib/react-query/hooks'
import { cn } from '@/lib/utils'
import { useDocsProject } from './DocsProjectContext'

const PICKER_PAGE_SIZE = 50

type DocsProjectPickerProps = {
  /** Label for the "no project" option. */
  noneLabel?: string
  className?: string
  align?: 'start' | 'end'
}

type OrganizationOption = { $id: string; name: string }

function useOrganizationOptions(enabled: boolean): {
  organizations: OrganizationOption[]
  isLoading: boolean
} {
  const { data, isLoading } = useQuery({
    ...organizationsQueryOptions(),
    enabled,
  })
  const organizations = useMemo(() => {
    const teams = (data as { teams?: unknown[] } | undefined)?.teams ?? []
    return teams.flatMap((team) => {
      const org = team as { $id?: unknown; name?: unknown }
      return typeof org.$id === 'string' && typeof org.name === 'string'
        ? [{ $id: org.$id, name: org.name }]
        : []
    })
  }, [data])
  return { organizations, isLoading: enabled && isLoading }
}

/** Picks the project the setup prompt names. Shown only while signed in. */
export function DocsProjectPicker({
  noneLabel = 'Let the agent choose',
  className,
  align = 'end',
}: DocsProjectPickerProps) {
  const { available, project, setProject, isAuthenticated } = useDocsProject()
  const [open, setOpen] = useState(false)
  const [orgId, setOrgId] = useState<string | null>(null)
  const [search, setSearch] = useState('')
  const [debouncedSearch, setDebouncedSearch] = useState('')

  const { organizations, isLoading: orgsLoading } = useOrganizationOptions(
    open && isAuthenticated,
  )

  useEffect(() => {
    const timer = window.setTimeout(
      () => setDebouncedSearch(search.trim()),
      250,
    )
    return () => window.clearTimeout(timer)
  }, [search])

  useEffect(() => {
    if (!open) {
      setSearch('')
      return
    }
    if (orgId && organizations.some((org) => org.$id === orgId)) return
    const preferred =
      organizations.find((org) => org.$id === project?.orgId) ??
      organizations[0]
    setOrgId(preferred?.$id ?? null)
  }, [open, orgId, organizations, project?.orgId])

  const listOrgId = open && isAuthenticated ? orgId : null
  const { data: scopeData } = useQuery(
    organizationProjectScopeQueryOptions(listOrgId),
  )
  const { projects, total, isFetching } = useProjectsForTeamInfinite(
    listOrgId,
    PICKER_PAGE_SIZE,
    debouncedSearch || undefined,
    undefined,
    scopeData ?? null,
  )

  if (!available || !isAuthenticated) return null

  const showSkeleton = (orgsLoading || isFetching) && projects.length === 0
  const hasMore = total > projects.length

  const triggerLabel = project
    ? formatProjectNameForDisplay(project.name, 28)
    : noneLabel

  const selectProject = (next: (typeof projects)[number]) => {
    if (!orgId) return
    setProject({ id: next.$id, name: next.name, region: next.region, orgId })
    setOpen(false)
  }

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button
          type="button"
          variant="outline"
          size="sm"
          aria-label={project ? `Project: ${project.name}` : 'Choose a project'}
          className={cn(
            'h-8 max-w-[240px] gap-1.5 px-2.5 text-[12px] font-normal',
            project
              ? 'text-foreground'
              : 'text-muted-foreground hover:text-foreground',
            className,
          )}
          {...analyticsAttrs('docs-agent-project-picker')}
        >
          {project ? (
            <InitialsAvatar
              name={project.name}
              size="xs"
              className="size-4 shrink-0 text-[8px]"
            />
          ) : (
            <FolderOpen className="size-3.5 shrink-0" aria-hidden />
          )}
          <span className="min-w-0 truncate">{triggerLabel}</span>
          <ChevronDown className="size-3 shrink-0 opacity-70" aria-hidden />
        </Button>
      </PopoverTrigger>
      <PopoverContent
        align={align}
        className="w-[300px] p-0"
        onWheelCapture={(event) => event.stopPropagation()}
      >
        <div>
          <div className="px-3 pb-2 pt-3">
            <p className="text-[13px] font-medium text-foreground">
              Project for your agent
            </p>
            <p className="mt-1 text-[12px] leading-5 text-muted-foreground">
              Optional. Without one, the agent links or creates a project for
              you.
            </p>
          </div>
          {organizations.length > 1 ? (
            <div className="px-3 pb-2">
              <Select value={orgId ?? undefined} onValueChange={setOrgId}>
                <SelectTrigger size="sm" className="h-8 w-full text-[12px]">
                  <SelectValue placeholder="Organization" />
                </SelectTrigger>
                <SelectContent>
                  {organizations.map((org) => (
                    <SelectItem
                      key={org.$id}
                      value={org.$id}
                      className="text-[12px]"
                    >
                      {org.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          ) : null}
          <Command shouldFilter={false} className="border-t border-border">
            <div className="relative">
              <CommandInput
                placeholder="Search projects..."
                value={search}
                onValueChange={setSearch}
                className="h-9 pe-8 text-[12px]"
              />
              <Loader2
                className={cn(
                  'pointer-events-none absolute end-3 top-1/2 size-3.5 -translate-y-1/2 animate-spin text-muted-foreground transition-opacity',
                  isFetching ? 'opacity-100' : 'opacity-0',
                )}
                aria-hidden
              />
            </div>
            <CommandList className="max-h-[240px] min-h-[120px] overflow-y-auto overscroll-contain">
              {showSkeleton ? (
                <div className="space-y-0.5 p-1" aria-hidden>
                  {Array.from({ length: 4 }, (_, index) => (
                    <div
                      key={index}
                      className="flex items-center gap-2 px-2 py-1.5"
                    >
                      <Skeleton className="size-4 shrink-0 rounded-full" />
                      <Skeleton
                        className="h-3.5 rounded-sm"
                        style={{ width: `${50 + (index % 3) * 14}%` }}
                      />
                    </div>
                  ))}
                </div>
              ) : (
                <>
                  <CommandEmpty className="py-6 text-center text-[12px] text-muted-foreground">
                    No projects found
                  </CommandEmpty>
                  <CommandGroup>
                    {projects.map((item) => {
                      const selected = project?.id === item.$id
                      return (
                        <CommandItem
                          key={item.$id}
                          value={item.$id}
                          onSelect={() => selectProject(item)}
                          className={cn(
                            'gap-2 px-2 py-1.5 text-[12px]',
                            selected && 'bg-accent/50',
                          )}
                        >
                          <InitialsAvatar
                            name={item.name}
                            size="xs"
                            className="size-4 shrink-0 text-[8px]"
                          />
                          <span className="min-w-0 flex-1 truncate">
                            {formatProjectNameForDisplay(item.name, 26)}
                          </span>
                          {item.region && item.region !== 'unknown' ? (
                            <span className="shrink-0 font-mono text-[10px] uppercase text-muted-foreground">
                              {item.region}
                            </span>
                          ) : null}
                          <Check
                            className={cn(
                              'size-3.5 shrink-0',
                              selected ? 'opacity-100' : 'opacity-0',
                            )}
                            aria-hidden
                          />
                        </CommandItem>
                      )
                    })}
                  </CommandGroup>
                  {hasMore ? (
                    <p className="px-3 pb-2 pt-1 text-[11px] text-muted-foreground">
                      Search to find more projects.
                    </p>
                  ) : null}
                </>
              )}
            </CommandList>
          </Command>
          {project ? (
            <div className="border-t border-border p-1">
              <button
                type="button"
                onClick={() => {
                  setProject(null)
                  setOpen(false)
                }}
                className="flex w-full cursor-pointer items-center gap-2 rounded-sm px-2 py-1.5 text-start text-[12px] text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
              >
                <X className="size-3.5 shrink-0" aria-hidden />
                {noneLabel}
              </button>
            </div>
          ) : null}
        </div>
      </PopoverContent>
    </Popover>
  )
}
