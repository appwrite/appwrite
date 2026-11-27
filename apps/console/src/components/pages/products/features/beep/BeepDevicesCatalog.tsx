import {
  KeyRound,
  Laptop,
  LayoutDashboard,
  Smartphone,
  SmartphoneNfc,
  SquareMousePointer,
  Wallet,
  WifiOff,
  type LucideIcon,
} from 'lucide-react'
import { riseStyle } from '@/components/pages/products/_components/ArtParts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

/** Where Beep runs and what comes with it, as an open spec list. */
const ITEMS: { title: string; description: string; icon: LucideIcon }[] = [
  {
    title: 'iPhone',
    description: 'iOS 16.4 or later, in light or dark mode with your system.',
    icon: Smartphone,
  },
  {
    title: 'iPad',
    description:
      'A dashboard layout with what is firing, usage, and projects side by side.',
    icon: LayoutDashboard,
  },
  {
    title: 'Android',
    description:
      'Phones and foldables, with critical alerts that ring through silent mode.',
    icon: SmartphoneNfc,
  },
  {
    title: 'Mac',
    description:
      'Runs on Macs with Apple silicon, with a sidebar instead of tabs.',
    icon: Laptop,
  },
  {
    title: 'Your Appwrite account',
    description:
      'Sign in with GitHub, Google, or email, with the same MFA as the Console.',
    icon: KeyRound,
  },
  {
    title: 'Works offline',
    description:
      'Lose signal and Beep shows the last data it loaded, for up to 24 hours.',
    icon: WifiOff,
  },
  {
    title: 'Shortcuts',
    description:
      'Long-press the app icon for firing alerts, or to snooze for an hour.',
    icon: SquareMousePointer,
  },
  {
    title: 'Free of charge',
    description:
      'Free on the App Store and Google Play, with no ads or in-app purchases.',
    icon: Wallet,
  },
]

export function BeepDevicesCatalog() {
  const t = useT()

  return (
    <ul className="mx-auto grid max-w-5xl gap-x-8 gap-y-8 text-start sm:grid-cols-2 lg:grid-cols-4">
      {ITEMS.map((item, index) => {
        const Icon = item.icon
        return (
          <li
            key={item.title}
            className="product-hero-rise border-t border-border pt-4"
            style={riseStyle(120 + index * 60)}
          >
            <Icon
              className={cn(
                'size-4',
                index % 2 === 0
                  ? 'text-[var(--tone-ink)]'
                  : 'text-foreground/70',
              )}
              strokeWidth={1.75}
              aria-hidden
            />
            <h4 className="mt-3 text-[14px] font-medium text-foreground">
              {t(item.title)}
            </h4>
            <p className="mt-1 text-[13px] leading-5 text-muted-foreground">
              {t(item.description)}
            </p>
          </li>
        )
      })}
    </ul>
  )
}
