import { createFileRoute } from '@tanstack/react-router'
import { z } from 'zod'
import {
  EducationJoinPreview,
  type EducationJoinPreviewState,
} from '@/components/pages/education/join/EducationJoinPreview'
import { pageTitle } from '@/lib/utils/page-title'

const educationJoinPreviewSearchSchema = z.object({
  view: z
    .enum(['landing', 'oauth-failure', 'loading', 'ineligible'])
    .optional(),
})

export const Route = createFileRoute('/_public/debug/education-join-preview')({
  validateSearch: educationJoinPreviewSearchSchema,
  head: () => ({
    meta: [{ title: pageTitle('Education program join preview') }],
  }),
  component: EducationJoinPreviewPage,
})

function EducationJoinPreviewPage() {
  const { view } = Route.useSearch()
  const state: EducationJoinPreviewState = view ?? 'landing'

  return <EducationJoinPreview state={state} />
}
