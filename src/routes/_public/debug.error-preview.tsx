import { createFileRoute } from '@tanstack/react-router'
import { ErrorComponent } from '@/components/error/Component'
import { pageTitle } from '@/lib/utils/page-title'

type ErrorPreviewSearch = {
  kind?: 'stale'
}

export const Route = createFileRoute('/_public/debug/error-preview')({
  validateSearch: (search: Record<string, unknown>): ErrorPreviewSearch => ({
    kind: search.kind === 'stale' ? 'stale' : undefined,
  }),
  head: () => ({ meta: [{ title: pageTitle('Error preview') }] }),
  component: ErrorPreviewPage,
})

const MOCK_ERROR = new Error(
  'Something went wrong while loading this resource. (Debug preview)',
)

const STALE_CHUNK_PREVIEW_ERROR = new Error(
  'Failed to fetch dynamically imported module: https://example.com/assets/View-abc123.js',
)

function ErrorPreviewPage() {
  const { kind } = Route.useSearch()
  const error = kind === 'stale' ? STALE_CHUNK_PREVIEW_ERROR : MOCK_ERROR

  return (
    <ErrorComponent
      error={error}
      info={{
        componentStack:
          ' at ErrorPreviewPage (debug.error-preview.tsx)\n    at RouterProvider',
      }}
      reset={() => {}}
      preview
    />
  )
}
