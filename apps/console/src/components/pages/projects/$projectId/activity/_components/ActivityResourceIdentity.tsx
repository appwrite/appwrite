import type { ReactNode } from 'react'
import type { LucideIcon } from 'lucide-react'
import {
  Activity,
  AppWindow,
  Archive,
  ArrowLeftRight,
  BarChart3,
  Bell,
  Braces,
  CalendarClock,
  CreditCard,
  Crosshair,
  Database,
  FileText,
  Fingerprint,
  Folder,
  GitBranch,
  Globe,
  Key,
  Link2,
  ListChecks,
  Mail,
  MapPin,
  Megaphone,
  Phone,
  Radio,
  RotateCcw,
  Rows3,
  Server,
  Shield,
  ShieldAlert,
  Smartphone,
  Table2,
  Tag,
  UserPlus,
  Users,
  Webhook,
  Zap,
} from '@/lib/icons'
import { CopyableId } from '@/components/global/shared/CopyableId'
import {
  resourceTypeVisual,
  type ActivityResourceVisualIcon,
} from '@/components/pages/projects/$projectId/activity/activity-utils'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

const RESOURCE_ICONS: Record<ActivityResourceVisualIcon, LucideIcon> = {
  document: FileText,
  collection: Folder,
  row: Rows3,
  table: Table2,
  database: Database,
  file: FileText,
  bucket: Folder,
  function: Zap,
  user: Users,
  team: Users,
  site: Globe,
  rule: ListChecks,
  identity: Fingerprint,
  message: Mail,
  topic: Megaphone,
  provider: Radio,
  subscriber: Bell,
  target: Crosshair,
  token: Key,
  webhook: Webhook,
  schedule: CalendarClock,
  migrations: ArrowLeftRight,
  report: BarChart3,
  vectorsdb: Database,
  project: Server,
  presence: Activity,
  platform: Smartphone,
  variable: Braces,
  labels: Tag,
  phone: Phone,
  app: AppWindow,
  archive: Archive,
  policy: Shield,
  restoration: RotateCcw,
  affiliate: Link2,
  billing: MapPin,
  payment: CreditCard,
  installation: GitBranch,
  membership: UserPlus,
  threat: ShieldAlert,
  cache: Database,
}

/**
 * Resource identity for the activity table and drawer: type-colored icon,
 * type name as the title, copyable id underneath.
 */
export function ActivityResourceIdentity({
  resourceType,
  resourceId,
  typeControl,
}: {
  /** Stored audit `resourceType` when present. */
  resourceType: string
  resourceId?: string | null
  /** Wrap the type label (e.g. click-to-filter). */
  typeControl?: (label: ReactNode) => ReactNode
}) {
  const t = useT()
  const visual = resourceTypeVisual(resourceType)
  const Icon = RESOURCE_ICONS[visual.icon]
  const label = t(visual.label)
  const id = resourceId?.trim() || ''

  return (
    <div className="flex items-center gap-2.5">
      <div
        className={cn(
          'flex h-7 w-7 shrink-0 items-center justify-center rounded-md',
          visual.tone,
        )}
        aria-hidden
      >
        <Icon className="h-3.5 w-3.5" />
      </div>
      <div className="flex flex-col items-start gap-1">
        {typeControl ? (
          typeControl(
            <span className="block whitespace-nowrap text-[13px] font-medium leading-snug text-foreground">
              {label}
            </span>,
          )
        ) : (
          <p className="whitespace-nowrap text-[13px] font-medium leading-snug text-foreground">
            {label}
          </p>
        )}
        {id ? (
          <CopyableId id={id} size="xs" maxWidth={280} />
        ) : (
          <p className="text-[11px] text-muted-foreground">-</p>
        )}
      </div>
    </div>
  )
}
