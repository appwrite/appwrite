import { Mail } from 'lucide-react'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import { productBentoContainer, productBentoIdle } from './MockSyntax'

const OAUTH_PROVIDERS = [
  {
    id: 'google',
    label: 'Google',
    icon: '/icons/google.svg',
    hoverClass:
      'group-hover:border-blue-500/35 group-hover:bg-blue-500/[0.06] motion-reduce:group-hover:border-border motion-reduce:group-hover:bg-background',
  },
  {
    id: 'github',
    label: 'GitHub',
    icon: '/icons/github.svg',
    hoverClass:
      'group-hover:border-foreground/25 group-hover:bg-foreground/[0.04] motion-reduce:group-hover:border-border motion-reduce:group-hover:bg-background',
  },
  {
    id: 'apple',
    label: 'Apple',
    icon: '/icons/apple.svg',
    hoverClass:
      'group-hover:border-foreground/30 group-hover:bg-foreground/[0.05] motion-reduce:group-hover:border-border motion-reduce:group-hover:bg-background',
  },
] as const

function MockEmailField({
  placeholder,
  typeDelayMs = 160,
}: {
  placeholder: string
  typeDelayMs?: number
}) {
  const t = useT()
  const typedText = 'paige@acme.io'
  const cursorDelay = typeDelayMs + typedText.length * 55

  return (
    <div className="space-y-1">
      <label className={cn('text-[11px] font-medium', productBentoIdle.text)}>
        {t('Email')}
      </label>
      <div className="relative rounded-md border border-border bg-background px-2.5 py-1.5 text-[12px]">
        <span className="text-muted-foreground transition-opacity duration-200 group-hover:opacity-0 motion-reduce:group-hover:opacity-100">
          {placeholder}
        </span>
        <span className="absolute inset-x-2.5 inset-y-0 flex items-center opacity-0 group-hover:opacity-100 motion-reduce:opacity-100">
          <span className={cn('inline-flex max-w-full items-center overflow-hidden whitespace-nowrap', productBentoIdle.text)}>
            <span
              className="inline-block max-w-0 overflow-hidden whitespace-nowrap group-hover:animate-[product-bento-email-reveal_1.4s_steps(18,end)_forwards] motion-reduce:max-w-none motion-reduce:group-hover:animate-none"
              style={{ animationDelay: `${typeDelayMs}ms` }}
            >
              {typedText}
            </span>
            <span
              className="ms-px inline-block h-2.5 w-px shrink-0 bg-muted-foreground opacity-0 group-hover:animate-[ai-mock-cursor-blink_1s_step-end_infinite] motion-reduce:opacity-100 motion-reduce:group-hover:animate-none"
              style={{ animationDelay: `${cursorDelay}ms` }}
            />
          </span>
        </span>
      </div>
    </div>
  )
}

function OAuthButton({
  provider,
  index,
  highlighted,
}: {
  provider: (typeof OAUTH_PROVIDERS)[number]
  index: number
  highlighted?: boolean
}) {
  return (
    <div
      className={cn(
        'flex items-center justify-center gap-1.5 rounded-md border border-border bg-background px-2 py-1.5 text-[12px] transition-[border-color,background-color,transform,box-shadow,color] duration-300',
        productBentoIdle.text,
        provider.hoverClass,
        highlighted &&
          'group-hover:animate-[product-bento-oauth-highlight_0.45s_ease-out_both] motion-reduce:group-hover:animate-none',
      )}
      style={highlighted ? { animationDelay: `${80 + index * 90}ms` } : undefined}
    >
      <img src={provider.icon} alt="" className={cn('size-3.5 shrink-0', productBentoIdle.providerIcon)} aria-hidden />
      <span className="truncate">{provider.label}</span>
    </div>
  )
}

export function AuthProductVisual() {
  const t = useT()
  return (
    <div className="absolute inset-0 flex flex-col">
      <div className="relative mx-auto flex h-full min-h-0 w-full max-w-[20rem] flex-col">
        <div className={cn('flex min-h-0 flex-1 flex-col', productBentoContainer.shell)}>
          <div className={cn(productBentoContainer.header, 'px-3 py-2')}>
            <div className="flex items-center gap-1.5">
              <span
                className={cn('size-2 rounded-full', productBentoIdle.brandDot)}
                aria-hidden
              />
              <span className={cn('text-[12px] font-semibold', productBentoIdle.text)}>Acme</span>
            </div>
          </div>

          <div className="space-y-3 overflow-hidden p-3">
            <div>
              <p className={cn('text-[13px] font-semibold', productBentoIdle.text)}>
                {t('Welcome back')}
              </p>
              <p className="mt-0.5 text-[11px] text-muted-foreground">
                {t('Sign in to your account')}
              </p>
            </div>

            <div className="grid grid-cols-3 gap-1.5">
              {OAUTH_PROVIDERS.map((provider, index) => (
                <OAuthButton
                  key={provider.id}
                  provider={provider}
                  index={index}
                  highlighted={index === 0}
                />
              ))}
            </div>

            <div className="flex items-center gap-2">
              <div className="h-px flex-1 bg-border" />
              <span className="text-[10px] uppercase tracking-wider text-muted-foreground">
                {t('or')}
              </span>
              <div className="h-px flex-1 bg-border" />
            </div>

            <div className="relative">
              <MockEmailField placeholder="you@company.com" typeDelayMs={220} />
            </div>

            <div
              className="flex items-center justify-center gap-1.5 rounded-md border border-border bg-muted/30 px-2 py-1.5 text-[12px] font-medium text-muted-foreground transition-[border-color,background-color,color] duration-300 group-hover:border-[color-mix(in_srgb,var(--brand-cta)_35%,var(--border))] group-hover:bg-[color-mix(in_srgb,var(--brand-cta)_12%,var(--background))] group-hover:text-foreground motion-reduce:group-hover:border-border motion-reduce:group-hover:bg-muted/30 motion-reduce:group-hover:text-muted-foreground"
              style={{ transitionDelay: '480ms' }}
            >
              <Mail className={cn('size-3.5 shrink-0', productBentoIdle.brandIcon)} aria-hidden />
              <span>{t('Send magic link')}</span>
            </div>
          </div>
        </div>

        <div
          className="pointer-events-none absolute -end-1 top-8 z-10 max-w-[calc(100%-0.5rem)] translate-x-2 rounded-lg border border-border bg-background/95 px-2 py-1.5 opacity-0 shadow-sm transition-[opacity,transform] duration-500 group-hover:translate-x-0 group-hover:opacity-100 motion-reduce:translate-x-0 motion-reduce:opacity-100 sm:-end-2"
          style={{ transitionDelay: '640ms' }}
        >
          <div className="flex items-center gap-1.5">
            <span className="flex size-4 shrink-0 items-center justify-center rounded bg-emerald-500/10 text-emerald-600 dark:text-emerald-400">
              <Mail className="size-2.5" aria-hidden />
            </span>
            <p className="text-[11px] font-medium text-foreground">
              {t('Check your inbox')}
            </p>
          </div>
        </div>
      </div>
    </div>
  )
}
