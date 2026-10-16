import { createFileRoute } from '@tanstack/react-router'
import { z } from 'zod'
import {
  AcceptInviteFlow,
  type AcceptInviteScreen,
} from '@/components/global/auth/AcceptInviteFlow'
import { DEBUG_DEMO_MOCK_EMAIL } from '@/lib/debug-demos/constants'
import { pageTitle } from '@/lib/utils/page-title'

const joinInvitePreviewSearchSchema = z.object({
  view: z
    .enum(['accept', 'wrong-account', 'invalid', 'error', 'success'])
    .optional(),
})

export const Route = createFileRoute('/_public/debug/join-invite-preview')({
  validateSearch: joinInvitePreviewSearchSchema,
  head: () => ({
    meta: [{ title: pageTitle('Organization invite preview') }],
  }),
  component: JoinInvitePreviewPage,
})

function JoinInvitePreviewPage() {
  const { view: viewParam } = Route.useSearch()
  const screen: AcceptInviteScreen = viewParam ?? 'accept'

  return (
    <AcceptInviteFlow
      preview
      screen={screen}
      teamName="Preview Org"
      accountLabel={DEBUG_DEMO_MOCK_EMAIL}
      errorMessage="This invitation has expired or was already accepted."
      errorIsAccountMismatch={false}
      onAccept={() => {}}
      onGoToDashboard={() => {}}
    />
  )
}
