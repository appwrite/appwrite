import { KeyRound, Mail } from 'lucide-react'
import { ProductFeaturePublicIcon } from '@/components/pages/products/features/_components/ProductFeaturePublicIcon'
import { ArtChip, ArtLiveDot, riseStyle } from '@/components/pages/products/_components/ArtParts'
import { useT } from '@/lib/i18n/translate'

const ORBIT_PROVIDERS = [
  'google',
  'github',
  'apple',
  'microsoft',
  'discord',
  'gitlab',
  'slack',
  'twitch',
] as const

function orbitPosition(index: number, total: number) {
  const angle = (index / total) * Math.PI * 2 - Math.PI / 2 + Math.PI / total
  return {
    left: `${(50 + 50 * Math.cos(angle)).toFixed(2)}%`,
    top: `${(50 + 50 * Math.sin(angle)).toFixed(2)}%`,
  }
}

const SIGN_IN_PROVIDERS = ['google', 'github', 'apple'] as const

const TEAM_MEMBERS = [
  { initials: 'WO', className: 'bg-[#7C67FE]/20 text-[#6a55f0] dark:text-[#a396ff]' },
  { initials: 'PD', className: 'bg-[#85DBD8]/25 text-[#1c8f8a] dark:text-[#85dbd8]' },
  { initials: 'HQ', className: 'bg-[#FE9567]/20 text-[#d9622c] dark:text-[#fe9567]' },
] as const

export function AuthHeroArt() {
  const t = useT()

  return (
    <div className="relative mx-auto aspect-square w-full max-w-[500px]">
      <div className="absolute inset-[3%] rounded-full border border-dashed border-border" aria-hidden />
      <div className="absolute inset-[20%] rounded-full border border-border/60" aria-hidden />
      <div
        className="absolute inset-[20%] rounded-full bg-[radial-gradient(circle,rgb(var(--tone-rgb)/0.16),transparent_70%)]"
        aria-hidden
      />

      <div className="product-hero-orbit absolute inset-[3%]" aria-hidden>
        {ORBIT_PROVIDERS.map((provider, index) => (
          <span
            key={provider}
            className="absolute -translate-x-1/2 -translate-y-1/2"
            style={orbitPosition(index, ORBIT_PROVIDERS.length)}
          >
            <span className="product-hero-orbit-reverse flex size-10 items-center justify-center rounded-xl border border-border bg-background shadow-sm dark:bg-card sm:size-11">
              <ProductFeaturePublicIcon src={`/icons/${provider}.svg`} className="size-[18px]" />
            </span>
          </span>
        ))}
      </div>

      <div
        className="product-hero-rise absolute left-1/2 top-1/2 z-[1] w-[min(280px,68%)] -translate-x-1/2 -translate-y-1/2"
        style={riseStyle(100)}
      >
        <div className="product-tone-shadow rounded-2xl border border-border bg-background p-4 dark:bg-card sm:p-5">
          <div className="flex items-center gap-1.5">
            <span className="size-2 rounded-full bg-[var(--brand-cta)]" aria-hidden />
            <span className="text-[12px] font-semibold text-foreground">Acme</span>
          </div>
          <p className="mt-3.5 text-[15px] font-semibold text-foreground">{t('Welcome back')}</p>
          <p className="mt-0.5 text-[12px] text-muted-foreground">{t('Sign in to your account')}</p>

          <div className="mt-4 grid grid-cols-3 gap-1.5">
            {SIGN_IN_PROVIDERS.map((provider) => (
              <span
                key={provider}
                className="flex h-8 items-center justify-center rounded-md border border-border bg-background dark:bg-muted/30"
              >
                <ProductFeaturePublicIcon src={`/icons/${provider}.svg`} className="size-3.5" />
              </span>
            ))}
          </div>

          <div className="my-3 flex items-center gap-2" aria-hidden>
            <div className="h-px flex-1 bg-border" />
            <span className="text-[10px] uppercase tracking-wider text-muted-foreground">{t('or')}</span>
            <div className="h-px flex-1 bg-border" />
          </div>

          <p className="text-[11px] font-medium text-muted-foreground">{t('Email')}</p>
          <div
            dir="ltr"
            className="mt-1 flex h-8 items-center rounded-md border border-border bg-background px-2.5 text-[12px] text-foreground dark:bg-muted/30"
          >
            <span className="product-hero-type inline-block overflow-hidden whitespace-nowrap">
              paige@acme.io
            </span>
            <span className="ms-px inline-block h-3 w-px animate-[ai-mock-cursor-blink_1s_step-end_infinite] bg-foreground/70 motion-reduce:animate-none" />
          </div>

          <div className="mt-3 flex h-8 items-center justify-center gap-1.5 rounded-md bg-[var(--brand-cta)] text-[12px] font-medium text-white">
            <Mail className="size-3.5" aria-hidden />
            {t('Send magic link')}
          </div>
        </div>
      </div>

      <ArtChip className="end-0 top-[10%] sm:-end-2" delayMs={500} floatDelayMs={0}>
        <div className="flex items-center gap-2">
          <span className="flex size-7 items-center justify-center rounded-md bg-[rgb(var(--tone-rgb)/0.14)] text-[var(--tone-ink)]">
            <KeyRound className="size-3.5" aria-hidden />
          </span>
          <div>
            <p className="text-[10px] text-muted-foreground">{t('Verification code')}</p>
            <p dir="ltr" className="font-mono text-[13px] font-medium tracking-[0.2em] text-foreground">
              482 913
            </p>
          </div>
        </div>
      </ArtChip>

      <ArtChip className="bottom-0 start-0 sm:-start-3 sm:bottom-[14%]" delayMs={800} floatDelayMs={900}>
        <div className="flex items-center gap-2">
          <span className="flex size-6 items-center justify-center rounded-md bg-emerald-500/10 text-emerald-600 dark:text-emerald-400">
            <Mail className="size-3" aria-hidden />
          </span>
          <p className="text-[12px] font-medium text-foreground">{t('Check your inbox')}</p>
        </div>
      </ArtChip>

      <ArtChip className="bottom-[3%] end-[8%] hidden sm:block" delayMs={1100} floatDelayMs={1600}>
        <div className="flex items-center gap-2.5">
          <div className="flex -space-x-1.5 rtl:space-x-reverse">
            {TEAM_MEMBERS.map((member) => (
              <span
                key={member.initials}
                className={`flex size-6 items-center justify-center rounded-full border-2 border-background text-[9px] font-semibold dark:border-card ${member.className}`}
              >
                {member.initials}
              </span>
            ))}
          </div>
          <div>
            <p className="text-[11px] font-medium text-foreground">{t('Team workspace')}</p>
            <p className="flex items-center gap-1.5 text-[10px] text-muted-foreground">
              <ArtLiveDot className="size-1.5" />
              {t('3 online')}
            </p>
          </div>
        </div>
      </ArtChip>
    </div>
  )
}
