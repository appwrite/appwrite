import { useNavigate } from '@tanstack/react-router'
import { useQueryClient } from '@tanstack/react-query'
import { OrganizationDeleteSettingsCard } from '@/components/pages/organizations/$orgId/overview/_components/OrganizationDeleteSettingsCard'
import { OrganizationIdSettingsCard } from '@/components/pages/organizations/$orgId/overview/_components/OrganizationIdSettingsCard'
import { OrganizationNameSettingsCard } from '@/components/pages/organizations/$orgId/overview/_components/OrganizationNameSettingsCard'
import { DisconnectPartnerManagerCard } from '@/components/pages/partners/$partnerId/_components/DisconnectPartnerManagerCard'
import { PartnerPaneTitleRow } from '@/components/pages/partners/$partnerId/_components/PaneHeader'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { canAccessOrgSettingsOverview } from '@/lib/console-access-checks'
import { useT } from '@/lib/i18n/translate'
import type { PartnerAwareOrganization } from '@/lib/partners'
import { useOrganizationScopes } from '@/lib/react-query/hooks'

export function ClientSettingsPane({
  partnerId,
  client,
}: {
  partnerId: string
  client: PartnerAwareOrganization
}) {
  const t = useT()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(client.$id)
  const canEditSettings = canAccessOrgSettingsOverview(access)
  const settingsDisabledTooltip = t(
    "You don't have permission to update organization settings.",
  )
  const deleteDisabledTooltip = t(
    "You don't have permission to delete this organization.",
  )

  const handleDeleted = async () => {
    await queryClient.refetchQueries({
      queryKey: ['partners', partnerId, 'clients'],
    })
    void navigate({
      to: '/partners/$partnerId/clients',
      params: { partnerId },
      replace: true,
    })
  }

  return (
    <>
      <PartnerPaneTitleRow title={t('Settings')} />
      <div className="min-h-0 flex-1 overflow-y-auto p-4">
        <div className="w-full space-y-4">
          <OrganizationNameSettingsCard
            organizationId={client.$id}
            organizationName={client.name ?? ''}
            canUpdate={canEditSettings}
            disabledTooltip={settingsDisabledTooltip}
            onUpdated={async () => {
              await queryClient.refetchQueries({
                queryKey: ['partners', partnerId, 'clients'],
              })
            }}
          />
          <OrganizationIdSettingsCard organizationId={client.$id} />
          {client.managerId === partnerId ? (
            <DisconnectPartnerManagerCard
              partnerId={partnerId}
              organizationId={client.$id}
              organizationName={client.name ?? client.$id}
              onDisconnected={handleDeleted}
            />
          ) : null}
          {features.multiTenancy ? (
            <OrganizationDeleteSettingsCard
              organizationId={client.$id}
              organizationName={client.name ?? ''}
              canDelete={canEditSettings}
              disabledTooltip={deleteDisabledTooltip}
              onDeleted={handleDeleted}
            />
          ) : null}
        </div>
      </div>
    </>
  )
}
