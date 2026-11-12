import { useT } from '@/lib/i18n/translate'

const LOCALHOST_ORIGINS = ['http://localhost', 'https://localhost']
const ANDROID_ORIGIN = 'android:apk-key-hash:'

/** Origins passkeys work on, with localhost shown once since any port works there. */
export function PasskeyOrigins({
  origins,
  'data-testid': testId,
}: {
  origins: string[]
  'data-testid'?: string
}) {
  const t = useT()
  const localhost = origins.some((origin) => LOCALHOST_ORIGINS.includes(origin))

  return (
    <ul
      className="space-y-1 text-[12px] font-mono text-muted-foreground"
      data-testid={testId}
    >
      {origins
        .filter((origin) => !LOCALHOST_ORIGINS.includes(origin))
        .map((origin) => (
          <li key={origin} className="flex min-w-0 gap-1">
            <span className="truncate" title={origin}>
              {origin}
            </span>{' '}
            {origin.startsWith(ANDROID_ORIGIN) && (
              <span className="shrink-0 font-sans">{t('(Android app)')}</span>
            )}
          </li>
        ))}
      {localhost && (
        <li>
          http://localhost <span className="font-sans">{t('(any port)')}</span>
        </li>
      )}
    </ul>
  )
}
