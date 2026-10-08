import type { ReactNode } from 'react'
import { useDebugMode } from '@/components/global/providers/DebugMode'
import { useT } from '@/lib/i18n/translate'

export function DedicatedDatabaseDebugMetricsSection({
  children,
}: {
  children: ReactNode
}) {
  const { isDebugModeOpen } = useDebugMode()
  const t = useT()

  if (!isDebugModeOpen) return null

  return (
    <section className="space-y-6 rounded-lg border border-purple-500/25 bg-purple-500/5 p-4 sm:p-6">
      <div>
        <p className="text-[10px] font-semibold uppercase tracking-wider text-purple-600/80 dark:text-purple-400/80">
          {t('Debug metrics')}
        </p>
      </div>
      <div className="space-y-6">{children}</div>
    </section>
  )
}
