import { createFileRoute } from '@tanstack/react-router'
import { z } from 'zod'
import { View } from '@/components/pages/zero/$zeroId/View'
import { zeroQueryOptions } from '@/lib/react-query/hooks/zero'
import { pageTitle } from '@/lib/utils/page-title'

const searchSchema = z.object({
  secret: z.string().optional(),
})

export const Route = createFileRoute('/_auth/zero/$zeroId')({
  component: ClaimZeroPage,
  validateSearch: searchSchema,
  loaderDeps: ({ search }) => ({ secret: search.secret }),
  loader: async ({ context, params, deps }) => {
    if (typeof window === 'undefined' || !deps.secret) return
    // The view shows an invalid or expired link itself.
    await context.queryClient
      .ensureQueryData(zeroQueryOptions(params.zeroId, deps.secret))
      .catch(() => undefined)
  },
  head: () => ({ meta: [{ title: pageTitle('Claim project') }] }),
})

function ClaimZeroPage() {
  const { zeroId } = Route.useParams()
  const { secret } = Route.useSearch()
  return <View zeroId={zeroId} secret={secret} />
}
