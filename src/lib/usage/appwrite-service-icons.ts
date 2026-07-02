import type { LucideIcon } from 'lucide-react'
import { ProjectServiceId } from '@appwrite.io/console'
import {
  Activity,
  Boxes,
  Building2,
  Code,
  Folder,
  GitBranch,
  Globe,
  Key,
  KeyRound,
  MessageSquare,
  MoreHorizontal,
  Network,
  Radio,
  ScanSearch,
  Upload,
  User,
  UserCircle,
  Users,
  Zap,
} from '@/lib/icons'
import { getScopeCategoryIcon } from '@/lib/console-project-scopes'
import { translate } from '@/lib/i18n/translate'
import {
  formatDatabaseServiceLabel,
  getDatabaseServiceLucideIcon,
} from '@/lib/databases/database-service-icons'

const USAGE_SERVICE_ICON_MAP: Record<string, LucideIcon> = {
  [ProjectServiceId.Account]: User,
  [ProjectServiceId.Avatars]: UserCircle,
  [ProjectServiceId.Locale]: Globe,
  [ProjectServiceId.Health]: Activity,
  [ProjectServiceId.Project]: Boxes,
  [ProjectServiceId.Storage]: Folder,
  [ProjectServiceId.Teams]: Building2,
  [ProjectServiceId.Users]: Users,
  [ProjectServiceId.Vcs]: GitBranch,
  [ProjectServiceId.Sites]: Globe,
  [ProjectServiceId.Functions]: Zap,
  [ProjectServiceId.Proxy]: Network,
  [ProjectServiceId.Graphql]: Code,
  [ProjectServiceId.Migrations]: Upload,
  [ProjectServiceId.Messaging]: MessageSquare,
  [ProjectServiceId.Advisor]: ScanSearch,
  [ProjectServiceId.Oauth2]: KeyRound,
  tokens: Key,
  realtime: Radio,
}

const USAGE_SERVICE_LABEL_MAP: Record<string, string> = {
  [ProjectServiceId.Account]: 'Account',
  [ProjectServiceId.Avatars]: 'Avatars',
  [ProjectServiceId.Locale]: 'Locale',
  [ProjectServiceId.Health]: 'Health',
  [ProjectServiceId.Project]: 'Project',
  [ProjectServiceId.Storage]: 'Storage',
  [ProjectServiceId.Teams]: 'Teams',
  [ProjectServiceId.Users]: 'Users',
  [ProjectServiceId.Vcs]: 'VCS',
  [ProjectServiceId.Sites]: 'Sites',
  [ProjectServiceId.Functions]: 'Functions',
  [ProjectServiceId.Proxy]: 'Proxy',
  [ProjectServiceId.Graphql]: 'GraphQL',
  [ProjectServiceId.Migrations]: 'Migrations',
  [ProjectServiceId.Messaging]: 'Messaging',
  [ProjectServiceId.Advisor]: 'Advisor',
  [ProjectServiceId.Oauth2]: 'OAuth2',
  tokens: 'Tokens',
  realtime: 'Realtime',
}

function normalizeUsageServiceKey(value: string): string {
  return value.trim().toLowerCase()
}

export function getUsageServiceIcon(service: string): LucideIcon {
  const key = normalizeUsageServiceKey(service)
  if (!key || key === 'unknown') return MoreHorizontal

  const databaseIcon = getDatabaseServiceLucideIcon(key)
  if (databaseIcon) return databaseIcon

  return (
    USAGE_SERVICE_ICON_MAP[key] ?? getScopeCategoryIcon(key, key)
  )
}

export function formatUsageServiceLabel(service: string): string {
  const key = normalizeUsageServiceKey(service)
  if (!key || key === 'unknown') return translate('Unknown')

  const databaseLabel = formatDatabaseServiceLabel(key)
  if (databaseLabel) return databaseLabel

  return translate(USAGE_SERVICE_LABEL_MAP[key] ?? service.trim())
}
