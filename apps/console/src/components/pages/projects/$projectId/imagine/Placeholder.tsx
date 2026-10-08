import { Link, useLocation, useNavigate } from '@tanstack/react-router'
import { useTheme } from 'next-themes'
import { Button } from '@/components/ui/button'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { useT } from '@/lib/i18n/translate'

export function ImaginePlaceholder() {
  const t = useT()
  const { currentUser, signOut } = useAuth()
  const location = useLocation()
  const navigate = useNavigate()
  const { theme } = useTheme()

  return (
    <div className="flex-grow flex flex-col justify-center items-center gap-6 text-center">
      <img
        src={
          theme === 'dark'
            ? '/imagine-logo-dark.svg'
            : '/imagine-logo-light.svg'
        }
        alt={t('Imagine Logo')}
        className="size-14"
      />

      {currentUser ? (
        <>
          <p className="text-foreground/70">
            {t('You are signed in as')}{' '}
            <span className="font-medium">{currentUser.email}</span>
          </p>
          <Button
            size="sm"
            onClick={async () => {
              await signOut()
              navigate({ to: '/' })
            }}
          >
            {t('Sign out')}
          </Button>
        </>
      ) : (
        <>
          <p className="text-foreground/70">{t('You are not signed in.')}</p>
          <Link
            to="/sign-in"
            search={{
              redirect: location.pathname,
            }}
            className="text-blue-500 underline"
          >
            <Button size="sm">{t('Sign in')}</Button>
          </Link>
        </>
      )}
    </div>
  )
}
