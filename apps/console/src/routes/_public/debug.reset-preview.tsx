import { createFileRoute, useNavigate } from '@tanstack/react-router'
import { z } from 'zod'
import { toast } from 'sonner'
import { Link } from '@tanstack/react-router'
import { Reset } from '@/components/global/auth/Reset'
import { AuthFlowPreviewLayout } from '@/components/global/auth/debug/AuthFlowPreviewLayout'
import { useT } from '@/lib/i18n/translate'
import { pageTitle } from '@/lib/utils/page-title'

const resetPreviewSearchSchema = z.object({
  view: z.enum(['form', 'success', 'invalid']).optional(),
})

export const Route = createFileRoute('/_public/debug/reset-preview')({
  validateSearch: resetPreviewSearchSchema,
  head: () => ({ meta: [{ title: pageTitle('Reset password preview') }] }),
  component: ResetPreviewPage,
})

function ResetPreviewPage() {
  const t = useT()
  const navigate = useNavigate()
  const { view = 'form' } = Route.useSearch()

  if (view === 'invalid') {
    return (
      <AuthFlowPreviewLayout width="illustration">
        <div className="rounded-lg border bg-card p-6 text-center">
          <h1 className="mb-2 text-2xl font-semibold tracking-tight">
            {t('Invalid reset link')}
          </h1>
          <p className="mb-4 text-sm text-muted-foreground">
            {t(
              'This password reset link is invalid or has expired. Please request a new one.',
            )}
          </p>
          <Link to="/debug/recovery-preview" className="link-neutral text-sm">
            {t('Request new reset link')}
          </Link>
        </div>
      </AuthFlowPreviewLayout>
    )
  }

  return (
    <AuthFlowPreviewLayout width="illustration">
      <Reset
        preview
        isSuccess={view === 'success'}
        isLoading={false}
        onSubmit={() => {
          toast.message('Preview only', {
            description: 'Password is not changed on this preview route.',
          })
          navigate({
            to: '/debug/reset-preview',
            search: { view: 'success' },
            replace: true,
          })
        }}
      />
    </AuthFlowPreviewLayout>
  )
}
