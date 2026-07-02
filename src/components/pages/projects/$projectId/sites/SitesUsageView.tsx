import { useT } from '@/lib/i18n/translate'

export function SitesUsageView() {
  const t = useT()
  return (
    <div className="mx-auto w-full max-w-7xl px-4 pb-4 sm:px-6 sm:pb-6">
      <div className="py-6">
        <h2 className="text-lg font-semibold">{t('Sites Usage')}</h2>
        <p className="text-sm text-muted-foreground mt-1">
          {t('View aggregated usage statistics across all sites')}
        </p>
        {/* TODO: Implement sites usage charts */}
      </div>
    </div>
  )
}
