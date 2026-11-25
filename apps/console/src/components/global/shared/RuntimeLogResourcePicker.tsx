import { useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Database, Globe, Layers, Terminal } from 'lucide-react'
import {
  SearchableSelect,
  type SearchableSelectItem,
} from '@/components/global/shared/SearchableSelect'
import {
  functionsQueryOptions,
  sitesQueryOptions,
  type RuntimeLogResourceType,
} from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'

/** Functions and sites listed for picking; the search box filters within them. */
const PICK_LIMIT = 100

/** Every resource (`type` null), every resource of one type, or one resource. */
export interface RuntimeLogResource {
  type: RuntimeLogResourceType | null
  id?: string
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
 * site or database, or one function or site, grouped by kind.
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
  // Load the lists once the menu is first opened, or when a resource is already picked
  const enabled = !!projectId && (open || !!value.id)
  const functions = useQuery({
    ...functionsQueryOptions(projectId, 0, PICK_LIMIT),
    enabled,
  })
  const sites = useQuery({
    ...sitesQueryOptions(projectId, 0, PICK_LIMIT),
    enabled,
  })

  const items = useMemo<SearchableSelectItem[]>(
    () => [
      { value: 'all', label: t('All resources'), icon: Layers },
      {
        value: 'functions',
        label: t('All functions'),
        icon: Terminal,
        group: 'Functions',
      },
      ...(functions.data?.functions ?? []).map((fn) => ({
        value: `functions:${fn.$id}`,
        label: fn.name,
        searchText: `${fn.name} ${fn.$id}`,
        icon: Terminal,
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
        icon: Globe,
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
      onValueChange={(next) => onValueChange(decode(next))}
      items={items}
      onOpenChange={setOpen}
      ariaLabel={t('Resource')}
      isFetching={functions.isFetching || sites.isFetching}
      searchPlaceholder="Search resources"
      emptyMessage="No resources found"
      // A picked resource outside the loaded page still names itself
      selectedName={value.id}
      triggerClassName="w-[200px]"
      contentClassName="w-[280px]"
      listClassName="min-h-0"
    />
  )
}
