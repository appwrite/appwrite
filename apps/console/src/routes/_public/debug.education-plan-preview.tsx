import { createFileRoute } from '@tanstack/react-router'
import { z } from 'zod'
import { EducationPlanCurtainView } from '@/components/global/layout/EducationPlanCurtain'
import { EDUCATION_PLAN_PREVIEW_VIEWS } from '@/lib/debug-demos/education-plan-preview'
import { billingPlansQueryOptions } from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'

const educationPlanPreviewSearchSchema = z.object({
  view: z.enum(EDUCATION_PLAN_PREVIEW_VIEWS).optional(),
})

const MOCK_OTHER_ORGANIZATIONS = [
  { id: 'demo-personal', name: 'Personal projects' },
  { id: 'demo-hackathon', name: 'Hackathon team' },
]

export const Route = createFileRoute('/_public/debug/education-plan-preview')({
  validateSearch: educationPlanPreviewSearchSchema,
  loader: async ({ context }) => {
    if (typeof window === 'undefined') return
    await context.queryClient
      .ensureQueryData(billingPlansQueryOptions())
      .catch(() => {
        // Plans need a console session. Signed out, the preview shows the
        // same "could not be loaded" state as production.
      })
  },
  head: () => ({
    meta: [{ title: pageTitle('Education plan curtain preview') }],
  }),
  component: EducationPlanPreviewPage,
})

function EducationPlanPreviewPage() {
  const { view = 'reminder' } = Route.useSearch()
  const ended = view === 'ended' || view === 'ended-single-org'

  return (
    <EducationPlanCurtainView
      key={view}
      ended={ended}
      daysLeft={view === 'last-day' ? 1 : 35}
      organizationName="Coursework projects"
      otherOrganizations={
        view === 'ended-single-org' ? [] : MOCK_OTHER_ORGANIZATIONS
      }
      onDismiss={view === 'reminder' ? () => {} : undefined}
      onContinue={() => {}}
    />
  )
}
