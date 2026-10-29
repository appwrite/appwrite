import { useQuery } from '@tanstack/react-query'
import { Link } from '@tanstack/react-router'
import { Globe } from 'lucide-react'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import {
  ResourceCard,
  RESOURCE_CARD_GRID_CLASSNAME,
} from '@/components/pages/projects/$projectId/shared/ResourceCard'
import {
  PartnerListLoading,
  PartnerPage,
} from '@/components/pages/partners/$partnerId/PageShell'
import { organizationDomainsQueryOptions } from '@/lib/react-query/hooks'
import {
  DOMAINS_DEFAULT_SORT_BY,
  DOMAINS_DEFAULT_SORT_ORDER,
} from '@/lib/react-query/hooks/domains'
import { GRID_DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'

export function PartnerDomains({ partnerId }: { partnerId: string }) {
  const { data, isLoading } = useQuery(
    organizationDomainsQueryOptions(
      partnerId,
      0,
      GRID_DEFAULT_PAGE_SIZE,
      undefined,
      undefined,
      DOMAINS_DEFAULT_SORT_BY,
      DOMAINS_DEFAULT_SORT_ORDER,
    ),
  )

  const domains = data?.domains ?? []

  return (
    <PartnerPage title="Domains">
      {isLoading ? (
        <PartnerListLoading label="Loading domains..." />
      ) : domains.length === 0 ? (
        <EmptyState
          icon={Globe}
          title="No domains yet"
          description="Create your first domain to get started"
          isEmpty
          variant="card"
        />
      ) : (
        <div className={RESOURCE_CARD_GRID_CLASSNAME}>
          {domains.map((domain) => (
            <Link
              key={domain.$id}
              to="/organizations/$orgId/domains/$domainId"
              params={{ orgId: partnerId, domainId: domain.$id }}
              target="_blank"
              rel="noopener noreferrer"
              className="block h-full min-w-0"
            >
              <ResourceCard
                title={domain.domain || domain.$id}
                resourceId={domain.$id}
                icon={Globe}
                metadata={[
                  {
                    label: 'Nameservers',
                    value: domain.nameservers || '3rd party',
                  },
                  {
                    label: 'Created',
                    value: domain.$createdAt ? (
                      <DateTooltip
                        date={domain.$createdAt}
                        className="text-[11px] font-medium text-muted-foreground"
                      />
                    ) : (
                      '—'
                    ),
                  },
                ]}
              />
            </Link>
          ))}
        </div>
      )}
    </PartnerPage>
  )
}
