import { useMemo, useState } from 'react'
import type { ComponentType } from 'react'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { Database, Globe, Layers, Zap } from 'lucide-react'
import { FrameworkIcon } from '@/components/global/shared/FrameworkIcon'
import { RuntimeIcon } from '@/components/global/shared/RuntimeIcon'
import {
  SearchableSelect,
  type SearchableSelectItem,
} from '@/components/global/shared/SearchableSelect'
import {
  functionsQueryOptions,
  sitesQueryOptions,
  type RuntimeLogResourceType,
} from '@/lib/react-query/hooks'
import { useDebouncedValue } from '@/lib/hooks/useDebouncedValue'
import { useT } from '@/lib/i18n/translate'
import type { RuntimeLogResource } from '@/lib/runtime-log-filters'

/** Functions and sites listed for picking; the search box asks the API for more. */
const PICK_LIMIT = 25

type Mark = ComponentType<{ className?: string }>

// One mark per runtime and framework, so list items keep their identity
const runtimeMarks = new Map<string, Mark>()
const frameworkMarks = new Map<string, Mark>()

/** A function's runtime logo, in the picker's icon slot. */
function runtimeMark(runtime: string): Mark {
  let mark = runtimeMarks.get(runtime)
  if (!mark) {
    mark = ({ className }) => (
      <RuntimeIcon runtime={runtime} size="sm" className={className} />
    )
    runtimeMarks.set(runtime, mark)
  }
  return mark
}

/** A site's framework logo, in the picker's icon slot. */
function frameworkMark(framework: string): Mark {
  let mark = frameworkMarks.get(framework)
  if (!mark) {
    mark = ({ className }) => (
      <FrameworkIcon framework={framework} size="sm" className={className} />
    )
    frameworkMarks.set(framework, mark)
  }
  return mark
}

function encode(resource: RuntimeLogResource): string {
  if (!resource.type) return 'all'
  return resource.id ? `${resource.type}:${resource.id}` : resource.type
}

function decode(value: string): RuntimeLogResource {
  if (value === 'all') return { type: null }
  const [type, id] = value.split(':', 2) as [RuntimeLogResourceType, string?]
  return id ? { type, id } : { type }
}

/**
 * The project Logs page's resource filter: every resource, every function,
 * site or database, or one function or site, grouped by kind. Kinds carry the
 * sidebar's icons; functions and sites their runtime and framework logos.
 */
export function RuntimeLogResourcePicker({
  projectId,
  value,
  onValueChange,
}: {
  projectId: string | null | undefined
  value: RuntimeLogResource
  onValueChange: (value: RuntimeLogResource) => void
}) {
  const t = useT()
  const [open, setOpen] = useState(false)
  const [search, setSearch] = useState('')
  const query = useDebouncedValue(search.trim(), 250) || undefined
  // Load the lists once the menu is first opened, or when a resource is already picked
  const enabled = !!projectId && (open || !!value.id)
  const functions = useQuery({
    ...functionsQueryOptions(projectId, 0, PICK_LIMIT, query),
    enabled,
    placeholderData: keepPreviousData,
  })
  const sites = useQuery({
    ...sitesQueryOptions(projectId, 0, PICK_LIMIT, query),
    enabled,
    placeholderData: keepPreviousData,
  })

  const items = useMemo<SearchableSelectItem[]>(
    () => [
      { value: 'all', label: t('All resources'), icon: Layers },
      {
        value: 'functions',
        label: t('All functions'),
        icon: Zap,
        group: 'Functions',
      },
      ...(functions.data?.functions ?? []).map((fn) => ({
        value: `functions:${fn.$id}`,
        label: fn.name,
        searchText: `${fn.name} ${fn.$id}`,
        icon: fn.runtime ? runtimeMark(fn.runtime) : Zap,
        group: 'Functions',
      })),
      {
        value: 'sites',
        label: t('All sites'),
        icon: Globe,
        group: 'Sites',
      },
      ...(sites.data?.sites ?? []).map((site) => ({
        value: `sites:${site.$id}`,
        label: site.name,
        searchText: `${site.name} ${site.$id}`,
        icon: site.framework ? frameworkMark(site.framework) : Globe,
        group: 'Sites',
      })),
      {
        value: 'databases',
        label: t('All databases'),
        icon: Database,
        group: 'Databases',
      },
    ],
    [t, functions.data, sites.data],
  )

  return (
    <SearchableSelect
      value={encode(value)}
      onValueChange={(next) =>
        onValueChange({
          ...decode(next),
          name: items.find((item) => item.value === next)?.label,
        })
      }
      items={items}
      onOpenChange={setOpen}
      // The API searches functions and sites, so large projects reach every one
      onSearchChange={setSearch}
      ariaLabel={t('Resource')}
      isFetching={functions.isFetching || sites.isFetching}
      searchPlaceholder="Search resources"
      emptyMessage="No resources found"
      selectedName={value.name ?? value.id}
      triggerClassName="h-9 w-[200px] shrink-0 text-[13px]"
      contentClassName="w-[280px]"
      listClassName="min-h-0"
    />
  )
}
