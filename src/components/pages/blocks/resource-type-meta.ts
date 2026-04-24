import {
  Boxes,
  Database,
  FolderGit2,
  Globe,
  HardDrive,
  Inbox,
  Package,
  Radio,
  Send,
  Users as UsersIcon,
  Workflow,
  Zap,
  type LucideIcon,
} from 'lucide-react'
import { ResourceType } from '@appwrite.io/console'

export type ResourceTypeMeta = {
  value: ResourceType
  label: string
  icon: LucideIcon
  description: string
}

export const RESOURCE_TYPE_META: Record<ResourceType, ResourceTypeMeta> = {
  [ResourceType.Projects]: {
    value: ResourceType.Projects,
    label: 'Projects',
    icon: FolderGit2,
    description: 'Entire projects',
  },
  [ResourceType.Functions]: {
    value: ResourceType.Functions,
    label: 'Functions',
    icon: Zap,
    description: 'Function collection',
  },
  [ResourceType.Function]: {
    value: ResourceType.Function,
    label: 'Function',
    icon: Zap,
    description: 'Single function',
  },
  [ResourceType.Execution]: {
    value: ResourceType.Execution,
    label: 'Execution',
    icon: Workflow,
    description: 'Function executions',
  },
  [ResourceType.Sites]: {
    value: ResourceType.Sites,
    label: 'Sites',
    icon: Globe,
    description: 'Hosted sites',
  },
  [ResourceType.Databases]: {
    value: ResourceType.Databases,
    label: 'Databases',
    icon: Database,
    description: 'Database collections',
  },
  [ResourceType.Buckets]: {
    value: ResourceType.Buckets,
    label: 'Buckets',
    icon: HardDrive,
    description: 'Storage buckets',
  },
  [ResourceType.Providers]: {
    value: ResourceType.Providers,
    label: 'Providers',
    icon: Boxes,
    description: 'Messaging providers',
  },
  [ResourceType.Topics]: {
    value: ResourceType.Topics,
    label: 'Topics',
    icon: Radio,
    description: 'Messaging topics',
  },
  [ResourceType.Subscribers]: {
    value: ResourceType.Subscribers,
    label: 'Subscribers',
    icon: UsersIcon,
    description: 'Topic subscribers',
  },
  [ResourceType.Messages]: {
    value: ResourceType.Messages,
    label: 'Messages',
    icon: Inbox,
    description: 'Messaging deliveries',
  },
  [ResourceType.Message]: {
    value: ResourceType.Message,
    label: 'Message',
    icon: Send,
    description: 'Single message',
  },
  [ResourceType.Backup]: {
    value: ResourceType.Backup,
    label: 'Backup',
    icon: Package,
    description: 'Backup archives',
  },
}

export const ORDERED_RESOURCE_TYPES: ResourceType[] = [
  ResourceType.Projects,
  ResourceType.Functions,
  ResourceType.Function,
  ResourceType.Execution,
  ResourceType.Sites,
  ResourceType.Databases,
  ResourceType.Buckets,
  ResourceType.Providers,
  ResourceType.Topics,
  ResourceType.Subscribers,
  ResourceType.Messages,
  ResourceType.Message,
  ResourceType.Backup,
]

export function getResourceTypeMeta(value: string): ResourceTypeMeta {
  return (
    RESOURCE_TYPE_META[value as ResourceType] ?? {
      value: value as ResourceType,
      label: value,
      icon: Package,
      description: value,
    }
  )
}
