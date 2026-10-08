import { useT } from '@/lib/i18n/translate'

export function MysqlSettingsLoading() {
  const t = useT()
  return (
    <div className="rounded-lg border border-border bg-card py-12 text-center">
      <p className="text-[13px] text-muted-foreground">
        {t('Loading settings...')}
      </p>
    </div>
  )
}
