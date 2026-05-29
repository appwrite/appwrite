import {
  Boxes,
  Clock,
  Database,
  FolderGit2,
  Layers,
  Lock,
  Radio,
  ScrollText,
  Server,
  type LucideIcon,
} from 'lucide-react'
import { CacheTarget, CacheDatabase, Region } from '@appwrite.io/console'

export type CacheTargetMeta = {
  value: CacheTarget
  label: string
  icon: LucideIcon
  description: string
  /** Only the data cache can be scoped down to a database/project/collection/document. */
  scopable: boolean
}

export const CACHE_TARGET_META: Record<CacheTarget, CacheTargetMeta> = {
  [CacheTarget.Cache]: {
    value: CacheTarget.Cache,
    label: 'Data cache',
    icon: Database,
    description:
      'Cached database reads. Scopable by project, collection, or document.',
    scopable: true,
  },
  [CacheTarget.Timelimit]: {
    value: CacheTarget.Timelimit,
    label: 'Time limits',
    icon: Clock,
    description: 'Rate-limit counters.',
    scopable: false,
  },
  [CacheTarget.Locks]: {
    value: CacheTarget.Locks,
    label: 'Locks',
    icon: Lock,
    description: 'Distributed locks.',
    scopable: false,
  },
  [CacheTarget.Pubsub]: {
    value: CacheTarget.Pubsub,
    label: 'Pub/Sub',
    icon: Radio,
    description: 'Pub/Sub channel state.',
    scopable: false,
  },
  [CacheTarget.Queue]: {
    value: CacheTarget.Queue,
    label: 'Queue',
    icon: Layers,
    description: 'Queued worker jobs.',
    scopable: false,
  },
  [CacheTarget.All]: {
    value: CacheTarget.All,
    label: 'Everything',
    icon: Boxes,
    description: 'Every cache target listed here.',
    scopable: false,
  },
}

export const ORDERED_CACHE_TARGETS: CacheTarget[] = [
  CacheTarget.Cache,
  CacheTarget.Timelimit,
  CacheTarget.Locks,
  CacheTarget.Pubsub,
  CacheTarget.Queue,
  CacheTarget.All,
]

export type CacheDatabaseMeta = {
  value: CacheDatabase
  label: string
  icon: LucideIcon
  description: string
  /** Whether this scope needs a project ID to target a specific project. */
  takesProject: boolean
}

export const CACHE_DATABASE_META: Record<CacheDatabase, CacheDatabaseMeta> = {
  [CacheDatabase.Console]: {
    value: CacheDatabase.Console,
    label: 'Console',
    icon: Server,
    description: 'Platform-wide console database.',
    takesProject: false,
  },
  [CacheDatabase.Project]: {
    value: CacheDatabase.Project,
    label: 'Project',
    icon: FolderGit2,
    description: "A project's own database.",
    takesProject: true,
  },
  [CacheDatabase.Logs]: {
    value: CacheDatabase.Logs,
    label: 'Logs',
    icon: ScrollText,
    description: "A project's logs database.",
    takesProject: true,
  },
}

export const ORDERED_CACHE_DATABASES: CacheDatabase[] = [
  CacheDatabase.Console,
  CacheDatabase.Project,
  CacheDatabase.Logs,
]

export type RegionOption = {
  value: Region | null
  label: string
}

/** `null` value means "no region" -> the endpoint clears the target in every region. */
export const REGION_OPTIONS: RegionOption[] = [
  { value: null, label: 'All regions' },
  { value: Region.Fra, label: 'fra' },
  { value: Region.Nyc, label: 'nyc' },
  { value: Region.Syd, label: 'syd' },
  { value: Region.Sfo, label: 'sfo' },
  { value: Region.Sgp, label: 'sgp' },
  { value: Region.Tor, label: 'tor' },
]
