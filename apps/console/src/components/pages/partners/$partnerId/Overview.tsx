import { useQuery } from '@tanstack/react-query'
import { Link } from '@tanstack/react-router'
import { AlertTriangle, Folder, Users, Wallet } from 'lucide-react'
import { PartnerPage } from '@/components/pages/partners/$partnerId/PageShell'
import { PartnerSupportCards } from '@/components/pages/partners/$partnerId/SupportCards'
import {
  RESOURCE_CARD_GRID_CLASSNAME,
  RESOURCE_CARD_INTERACTIVE_CLASSNAME,
  RESOURCE_CARD_PADDED_CLASSNAME,
} from '@/components/pages/projects/$projectId/shared/ResourceCard'
import {
  activeProjectsQueryOptions,
  partnerClientsQueryOptions,
  useOrganizationById,
} from '@/lib/react-query/hooks'
import { GRID_DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import {
  isPartnerClientBudgetCapReached,
  isPartnerClientPaymentIssue,
  type PartnerAwareOrganization,
} from '@/lib/partners'
import { cn } from '@/lib/utils'

export function PartnerOverview({ partnerId }: { partnerId: string }) {
  const { organization } = useOrganizationById(partnerId)
  const partner = organization as PartnerAwareOrganization | null
  const { data: clients } = useQuery(partnerClientsQueryOptions(partnerId))
  const { data: projects } = useQuery(
    activeProjectsQueryOptions(partnerId, 0, GRID_DEFAULT_PAGE_SIZE),
  )
  const clientOrgs = clients?.teams ?? []

  const failed = clientOrgs.filter((client) =>
    isPartnerClientPaymentIssue(client),
  ).length
  const nearBudget = clientOrgs.filter((client) =>
    isPartnerClientBudgetCapReached(client),
  ).length

  return (
    <PartnerPage title="Overview">
      <div className={RESOURCE_CARD_GRID_CLASSNAME}>
        <StatCard
          icon={Users}
          label="Clients"
          value={String(clients?.total ?? clients?.teams.length ?? 0)}
          partnerId={partnerId}
          to="/partners/$partnerId/clients"
        />
        <StatCard
          icon={AlertTriangle}
          label="Invoice issues"
          value={String(failed)}
          partnerId={partnerId}
          to="/partners/$partnerId/billing"
        />
        <StatCard
          icon={Wallet}
          label="At budget cap"
          value={String(nearBudget)}
          partnerId={partnerId}
          to="/partners/$partnerId/billing"
        />
        <StatCard
          icon={Folder}
          label="Playground projects"
          value={String(projects?.total ?? projects?.projects?.length ?? 0)}
          partnerId={partnerId}
          to="/partners/$partnerId/projects"
        />
      </div>
      <PartnerSupportCards
        partnerId={partnerId}
        slackUrl={partner?.programSlackChannelUrl}
        slackName={partner?.programSlackChannelName}
      />
    </PartnerPage>
  )
}

function StatCard({
  icon: Icon,
  label,
  value,
  partnerId,
  to,
}: {
  icon: typeof Users
  label: string
  value: string
  partnerId: string
  to:
    | '/partners/$partnerId/clients'
    | '/partners/$partnerId/billing'
    | '/partners/$partnerId/projects'
}) {
  return (
    <Link to={to} params={{ partnerId }} className="block h-full min-w-0">
      <div
        className={cn(
          RESOURCE_CARD_PADDED_CLASSNAME,
          RESOURCE_CARD_INTERACTIVE_CLASSNAME,
          'flex h-full flex-col',
        )}
      >
        <div className="flex items-center justify-between gap-2">
          <p className="text-[13px] font-medium text-muted-foreground">{label}</p>
          <Icon className="h-4 w-4 text-muted-foreground" />
        </div>
        <p className="mt-3 text-2xl font-semibold text-foreground">{value}</p>
      </div>
    </Link>
  )
}
