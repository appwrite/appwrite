import { OrganizationMembersManager } from '@/components/pages/organizations/$orgId/overview/_components/OrganizationMembersManager'
import { PartnerTwoRowPaneHeader } from '@/components/pages/partners/$partnerId/_components/PaneHeader'
import {
  PARTNER_PANE_HEADER_BUTTON_CLASSNAME,
  PARTNER_PANE_HEADER_SEARCH_INPUT_CLASSNAME,
  PARTNER_PANE_HEADER_SEARCH_WRAP_CLASSNAME,
} from '@/components/pages/partners/$partnerId/_components/pane-header'
import { useT } from '@/lib/i18n/translate'

export function ClientMembersList({
  organizationId,
}: {
  organizationId: string
}) {
  const t = useT()

  return (
    <OrganizationMembersManager
      organizationId={organizationId}
      inviteButtonClassName={PARTNER_PANE_HEADER_BUTTON_CLASSNAME}
      searchWrapClassName={PARTNER_PANE_HEADER_SEARCH_WRAP_CLASSNAME}
      searchInputClassName={PARTNER_PANE_HEADER_SEARCH_INPUT_CLASSNAME}
      header={({ inviteButton, searchField }) => (
        <PartnerTwoRowPaneHeader
          title={t('Members')}
          actions={inviteButton}
          toolbar={searchField}
        />
      )}
    />
  )
}
