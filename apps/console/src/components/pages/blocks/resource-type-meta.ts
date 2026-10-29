import {
  Boxes,
  Database,
  Eye,
  FolderGit2,
  Globe,
  HardDrive,
  Inbox,
  Package,
  Radio,
  ShieldX,
  Users as UsersIcon,
  Zap,
  type LucideIcon,
} from 'lucide-react'
import { BlockMode, BlockResourceType } from '@appwrite.io/console'

export type ResourceTypeMeta = {
  value: BlockResourceType
  label: string
  icon: LucideIcon
  description: string
}

export const RESOURCE_TYPE_META: Record<BlockResourceType, ResourceTypeMeta> = {
  [BlockResourceType.Projects]: {
    value: BlockResourceType.Projects,
    label: 'Projects',
    icon: FolderGit2,
    description: 'Entire projects',
  },
  [BlockResourceType.Functions]: {
    value: BlockResourceType.Functions,
    label: 'Functions',
    icon: Zap,
    description: 'Function collection',
  },
  [BlockResourceType.Sites]: {
    value: BlockResourceType.Sites,
    label: 'Sites',
    icon: Globe,
    description: 'Hosted sites',
  },
  [BlockResourceType.Databases]: {
    value: BlockResourceType.Databases,
    label: 'Databases',
    icon: Database,
    description: 'Database collections',
  },
  [BlockResourceType.Buckets]: {
    value: BlockResourceType.Buckets,
    label: 'Buckets',
    icon: HardDrive,
    description: 'Storage buckets',
  },
  [BlockResourceType.Providers]: {
    value: BlockResourceType.Providers,
    label: 'Providers',
    icon: Boxes,
    description: 'Messaging providers',
  },
  [BlockResourceType.Topics]: {
    value: BlockResourceType.Topics,
    label: 'Topics',
    icon: Radio,
    description: 'Messaging topics',
  },
  [BlockResourceType.Subscribers]: {
    value: BlockResourceType.Subscribers,
    label: 'Subscribers',
    icon: UsersIcon,
    description: 'Topic subscribers',
  },
  [BlockResourceType.Messages]: {
    value: BlockResourceType.Messages,
    label: 'Messages',
    icon: Inbox,
    description: 'Messaging deliveries',
  },
}

export const ORDERED_RESOURCE_TYPES: BlockResourceType[] = [
  BlockResourceType.Projects,
  BlockResourceType.Functions,
  BlockResourceType.Sites,
  BlockResourceType.Databases,
  BlockResourceType.Buckets,
  BlockResourceType.Providers,
  BlockResourceType.Topics,
  BlockResourceType.Subscribers,
  BlockResourceType.Messages,
]

export function getResourceTypeMeta(value: string): ResourceTypeMeta {
  return (
    RESOURCE_TYPE_META[value as BlockResourceType] ?? {
      value: value as BlockResourceType,
      label: value,
      icon: Package,
      description: value,
    }
  )
}

export type BlockModeMeta = {
  value: BlockMode
  label: string
  icon: LucideIcon
  description: string
}

export const BLOCK_MODE_META: Record<BlockMode, BlockModeMeta> = {
  [BlockMode.Full]: {
    value: BlockMode.Full,
    label: 'Full',
    icon: ShieldX,
    description: 'Blocks reads and writes',
  },
  [BlockMode.Readonly]: {
    value: BlockMode.Readonly,
    label: 'Read-only',
    icon: Eye,
    description: 'Blocks writes, allows reads',
  },
}

/**
 * Read-only mode only makes sense for databases (it blocks database writes
 * while still allowing reads). Every other resource type is full-block only.
 */
export function resourceTypeSupportsReadonly(type: BlockResourceType): boolean {
  return type === BlockResourceType.Databases
}

/** Resolve mode meta from the API's free-form `mode` string, defaulting to full. */
export function getBlockModeMeta(mode: string | null | undefined): BlockModeMeta {
  if (mode === BlockMode.Readonly) return BLOCK_MODE_META[BlockMode.Readonly]
  return BLOCK_MODE_META[BlockMode.Full]
}
