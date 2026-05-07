import { Mail, Phone, Bell } from 'lucide-react'
import { PUBLIC_ICON_MUTED_CLASSES } from '@/lib/public-icon-classes'
import { cn } from '@/lib/utils'

interface MessagingProviderIconProps {
  /**
   * User-visible instance name (e.g. for `img` alt). Prefer passing this together with
   * {@link serviceKey} from `Models.Provider`.
   */
  providerName?: string
  /**
   * Service identifier from the API (`Models.Provider.provider`), e.g. `twilio`, `mailgun`,
   * `fcm`. Used to resolve branded icons; instance {@link providerName} is often not the slug.
   */
  serviceKey?: string
  providerType?: 'email' | 'sms' | 'push'
  className?: string
  size?: 'sm' | 'md' | 'lg'
}

const sizeClasses = {
  sm: 'h-5 w-5',
  md: 'h-6 w-6',
  lg: 'h-8 w-8',
}

// Map `Models.Provider.provider` (service slug) to icon files under /public/icons
const providerIconMap: Record<string, string> = {
  // SMS
  twilio: 'twilio.svg',
  msg91: 'msg91.svg',
  telesign: 'telesign.svg',
  textmagic: 'textmagic.svg',
  vonage: 'vonage.svg',
  // Email
  mailgun: 'mailgun.svg',
  sendgrid: 'sendgrid.svg',
  resend: 'resend.svg',
  // Push
  fcm: 'firebase.svg',
  apns: 'apple.svg',
}

export function MessagingProviderIcon({
  providerName,
  serviceKey,
  providerType,
  className,
  size = 'md',
}: MessagingProviderIconProps) {
  const slugSource = serviceKey ?? providerName
  const normalized = slugSource?.toLowerCase().trim() || ''
  const iconFile = providerIconMap[normalized]
  const sizeClass = sizeClasses[size as keyof typeof sizeClasses]
  const altLabel = providerName?.trim() || serviceKey?.trim() || 'Provider'

  // If we have an icon file, use it
  if (iconFile) {
    return (
      <img
        src={`/icons/${iconFile}`}
        alt={altLabel}
        className={cn(sizeClass, PUBLIC_ICON_MUTED_CLASSES, className)}
      />
    )
  }

  // Fallback to type-based icons
  const FallbackIcon =
    providerType === 'email' ? Mail : providerType === 'sms' ? Phone : Bell

  return <FallbackIcon className={cn(sizeClass, className)} />
}
