import { useParams, useNavigate } from '@tanstack/react-router'
import { useProjectSite } from '@/lib/react-query/hooks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import {
  SettingsCardsList,
  type SettingsCardItem,
} from '@/components/global/shared/settings-search/SettingsCardsList'
import { NameCard } from './NameCard'
import { SiteDetailsCard } from './SiteDetailsCard'
import { SiteStatusCard } from './SiteStatusCard'
import { DangerZoneCard } from './DangerZoneCard'
import { PreviewDomainCard } from '../../shared/PreviewDomainCard'
import { useT } from '@/lib/i18n/translate'

export function View() {
  const t = useT()
  const { projectId, siteId } = useParams({ strict: false })
  const navigate = useNavigate()
  const { isCloud } = useConsoleProfile()
  const { data: site, isLoading: siteLoading } = useProjectSite(
    projectId,
    siteId,
  )

  const handleDelete = () => {
    navigate({
      to: '/projects/$projectId/sites',
      params: { projectId: projectId! },
    })
  }

  if (siteLoading) {
    return (
      <div className="rounded-lg border border-border bg-card py-12 text-center">
        <p className="text-[13px] text-muted-foreground">
          {t('Loading settings...')}
        </p>
      </div>
    )
  }

  if (!site) return null

  const cards: SettingsCardItem[] = [
    {
      id: 'details',
      search: {
        title: 'Details',
        keywords: ['id', 'created', 'updated', 'identifiers'],
      },
      node: <SiteDetailsCard site={site} />,
    },
    {
      id: 'name',
      search: {
        title: 'Name',
        keywords: ['rename', 'display', 'site name'],
      },
      node: <NameCard projectId={projectId} siteId={siteId} site={site} />,
    },
    {
      id: 'status',
      search: {
        title: 'Status',
        description: 'Enable or disable this site without deleting it.',
        keywords: ['enabled', 'disabled', 'toggle'],
      },
      node: (
        <SiteStatusCard projectId={projectId} siteId={siteId} site={site} />
      ),
    },
    ...(isCloud
      ? [
          {
            id: 'preview-domain',
            search: {
              title: 'Preview domain',
              description:
                'Deployment and branch previews get a generated URL on a shared Appwrite domain.',
              keywords: ['preview', 'domain', 'branch', 'custom', 'enterprise'],
            },
            node: <PreviewDomainCard projectId={projectId} />,
          },
        ]
      : []),
    {
      id: 'delete',
      search: {
        title: 'Delete site',
        keywords: ['delete', 'remove', 'destroy', 'danger'],
      },
      node: (
        <DangerZoneCard
          projectId={projectId}
          siteId={siteId}
          site={site}
          onDelete={handleDelete}
        />
      ),
    },
  ]

  return <SettingsCardsList cards={cards} />
}
