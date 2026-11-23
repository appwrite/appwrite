import { useQuery } from '@tanstack/react-query'
import { useInitPresenceBaseline } from '@/lib/init/use-init-presence-baseline'
import { consoleAccountQueryOptions } from '@/lib/react-query/hooks/auth'
import type { InitDisplayEvent } from '@/lib/init/types'

export function InitPresenceBaselineSync({ event }: { event: InitDisplayEvent }) {
  const { data: account } = useQuery(consoleAccountQueryOptions())
  useInitPresenceBaseline(event, account?.$id)
  return null
}
