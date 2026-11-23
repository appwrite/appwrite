import { createFileRoute } from '@tanstack/react-router'
import { z } from 'zod'
import { MagicUrlLoginCard } from '@/components/global/auth/MagicUrlLoginCard'
import { useT } from '@/lib/i18n/translate'
import { pageTitle } from '@/lib/utils/page-title'

const magicUrlPreviewSearchSchema = z.object({
  view: z.enum(['signing-in', 'error']).optional(),
})

export const Route = createFileRoute('/_public/debug/magic-url-preview')({
  validateSearch: magicUrlPreviewSearchSchema,
  head: () => ({ meta: [{ title: pageTitle('Magic URL preview') }] }),
  component: MagicUrlPreviewPage,
})

function MagicUrlPreviewPage() {
  const t = useT()
  const { view = 'signing-in' } = Route.useSearch()

  return (
    <MagicUrlLoginCard
      signInTo="/debug/sign-in-preview"
      errorMessage={
        view === 'error' ? t('The magic URL is invalid or has expired.') : null
      }
    />
  )
}
