import { useMemo } from 'react'
import { useDebugOverrides } from '@/lib/debug-overrides'
import {
  detectUserOs,
  getUserOsLabel,
  orderOsOptions,
  resolveUserOs,
  type UserOs,
  type UserOsOverride,
} from '@/lib/user-os'

/**
 * Resolved client OS for UI, respecting the debug menu override.
 */
export function useUserOs(): {
  os: UserOs
  detectedOs: UserOs
  override: UserOsOverride
  label: string
  orderOptions: (options?: readonly UserOs[]) => UserOs[]
} {
  const { userOs: override } = useDebugOverrides()
  const detectedOs = useMemo(() => detectUserOs(), [])
  const os = resolveUserOs(override)

  return {
    os,
    detectedOs,
    override,
    label: getUserOsLabel(os),
    orderOptions: (options = ['macos', 'windows', 'linux'] as const) =>
      orderOsOptions(options, os),
  }
}
