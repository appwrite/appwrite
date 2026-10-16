import { useT } from '@/lib/i18n/translate'

interface ComingSoonViewProps {
  title: string
  comingSoon?: boolean
}

export function ComingSoonView({ title, comingSoon }: ComingSoonViewProps) {
  const t = useT()
  return (
    <div className="flex h-full min-h-[400px] items-center justify-center px-4">
      <div className="text-center">
        <div className="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-muted ring-1 ring-border">
          <span className="text-xl">🚧</span>
        </div>
        <h2 className="mb-1.5 text-[15px] font-medium text-foreground">
          {t(title)}
        </h2>
        <p className="text-[13px] text-muted-foreground">
          {comingSoon
            ? t('This feature is coming soon')
            : t('This section is under construction')}
        </p>
      </div>
    </div>
  )
}
