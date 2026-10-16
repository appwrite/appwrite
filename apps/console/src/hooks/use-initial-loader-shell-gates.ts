import { useEffect, useState } from 'react'

import {
  areInitialLoaderShellGatesReady,
  subscribeInitialLoaderShellGates,
} from '@/lib/initial-loader/shell-gates'

/** Subscribes to shell-gate updates for the initial fullscreen loader. */
export function useInitialLoaderShellGatesReady(pathname: string): boolean {
  const [ready, setReady] = useState(() =>
    areInitialLoaderShellGatesReady(pathname),
  )

  useEffect(() => {
    setReady(areInitialLoaderShellGatesReady(pathname))
    return subscribeInitialLoaderShellGates(() => {
      setReady(areInitialLoaderShellGatesReady(pathname))
    })
  }, [pathname])

  return ready
}
