import { createFileRoute } from '@tanstack/react-router'
import { ErrorComponent } from '@/components/error/Component'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/_public/debug/error-preview')({
  head: () => ({ meta: [{ title: pageTitle('Error preview') }] }),
  component: ErrorPreviewPage,
})

const MOCK_ERROR = new Error(
  'Something went wrong while loading this resource. (Debug preview)',
)

function ErrorPreviewPage() {
  return (
    <ErrorComponent
      error={MOCK_ERROR}
      info={{
        componentStack:
          ' at ErrorPreviewPage (debug.error-preview.tsx)\n    at RouterProvider',
      }}
      reset={() => {}}
      preview
    />
  )
}
