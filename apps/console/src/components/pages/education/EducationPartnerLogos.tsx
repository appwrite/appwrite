import { useEffect, useState } from 'react'
import { useTheme } from 'next-themes'
import { cn } from '@/lib/utils'

type EducationPartnerLogosProps = {
  className?: string
}

const LOGO_HEIGHT_CLASS = 'h-5 w-auto'

export function EducationPartnerLogos({ className }: EducationPartnerLogosProps) {
  const [mounted, setMounted] = useState(false)
  const { theme, resolvedTheme } = useTheme()

  useEffect(() => {
    setMounted(true)
  }, [])

  const isDark = mounted ? (resolvedTheme ?? theme) === 'dark' : true
  const appwriteLogo = isDark
    ? '/images/education/appwrite-logotype-white.svg'
    : '/images/education/appwrite-logotype-black.svg'
  const githubLogo = isDark
    ? '/images/education/github-mark.svg'
    : '/images/education/github-lockup-black.svg'

  return (
    <div className={cn('inline-flex items-center justify-center', className)}>
      <img
        src={appwriteLogo}
        alt="Appwrite"
        className={cn(LOGO_HEIGHT_CLASS, 'pe-5')}
        loading="lazy"
      />
      <span
        className="h-5 w-px shrink-0 bg-foreground/25 dark:bg-foreground/35"
        aria-hidden
      />
      <img
        src={githubLogo}
        alt="GitHub"
        className={cn(LOGO_HEIGHT_CLASS, 'ps-5')}
        loading="lazy"
      />
    </div>
  )
}
