import { useT } from '@/lib/i18n/translate'

const LOCALHOST_ORIGINS = ['http://localhost', 'https://localhost']

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
          <li key={origin}>{origin}</li>
        ))}
      {localhost && (
        <li>
          http://localhost <span className="font-sans">{t('(any port)')}</span>
        </li>
      )}
    </ul>
  )
}
