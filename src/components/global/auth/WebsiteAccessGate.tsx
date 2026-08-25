import { useLayoutEffect, useState, type FormEvent, type ReactNode } from 'react'
import { AppwriteLogo } from '@/components/global/auth/AppwriteLogo'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { useT } from '@/lib/i18n/translate'
import { getRuntimeConfig } from '@/lib/runtime-config'
import {
  hasWebsiteAccessCookie,
  isWebsiteAccessEnabled,
  setWebsiteAccessCookie,
  shouldShowWebsiteAccessGate,
  WEBSITE_ACCESS_COOKIE_NAME,
  WEBSITE_ACCESS_PASSWORD,
} from '@/lib/website-access'

const BOOT_COVER_ID = 'website-access-boot-cover'

/**
 * Runs before first paint. Covers the entire viewport when the access cookie is
 * missing so SSR/prerendered HTML never flashes. Skips public paths (e.g. `/i/*`).
 */
export const WEBSITE_ACCESS_BOOT_SCRIPT = `(function(){
  try {
    var cfg = window.__APP_CONFIG__ || {};
    var flag = String(cfg.websiteAccess || '').toLowerCase().trim();
    if (flag === 'false' || flag === '0' || flag === 'disabled') return;
    var path = (location.pathname || '/').replace(/\\/+$/, '') || '/';
    if (path === '/i' || path.indexOf('/i/') === 0) return;
    var re = new RegExp('(?:^|;\\\\s*)${WEBSITE_ACCESS_COOKIE_NAME}=([^;]*)');
    var m = document.cookie.match(re);
    if (m && m[1] && m[1].trim()) return;
    document.documentElement.classList.add('website-access-locked');
    var el = document.createElement('div');
    el.id = '${BOOT_COVER_ID}';
    el.setAttribute('aria-hidden', 'true');
    el.style.cssText = 'position:fixed;inset:0;z-index:2147483647;background:#ffffff;';
    if (document.documentElement.classList.contains('dark')) {
      el.style.background = '#000000';
    }
    var mount = function(){
      if (!document.getElementById('${BOOT_COVER_ID}')) {
        (document.body || document.documentElement).appendChild(el);
      }
    };
    if (document.body) mount();
    else document.addEventListener('DOMContentLoaded', mount);
  } catch (e) {}
})()`

function removeBootCover() {
  if (typeof document === 'undefined') return
  document.getElementById(BOOT_COVER_ID)?.remove()
  document.documentElement.classList.remove('website-access-locked')
}

function WebsiteAccessScreen({ onSuccess }: { onSuccess: () => void }) {
  const t = useT()
  const [password, setPassword] = useState('')
  const [error, setError] = useState<string | null>(null)

  useLayoutEffect(() => {
    removeBootCover()
  }, [])

  const handleSubmit = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    setError(null)

    if (password !== WEBSITE_ACCESS_PASSWORD) {
      setError(t('Incorrect password'))
      return
    }

    setWebsiteAccessCookie()
    removeBootCover()
    onSuccess()
  }

  return (
    <div
      data-website-access-gate=""
      className="bg-background fixed inset-0 z-[2147483646] flex h-dvh max-h-dvh w-full flex-col items-center justify-center overflow-hidden p-6 md:p-10"
    >
      <div className="w-full max-w-sm space-y-8">
        <div className="flex flex-col items-center gap-6 text-center">
          <AppwriteLogo className="h-6 w-auto" />
          <div className="space-y-2">
            <h1 className="text-xl font-semibold tracking-tight text-foreground">
              {t('Password protected')}
            </h1>
            <p className="text-[13px] text-muted-foreground">
              {t('Enter the password to continue.')}
            </p>
          </div>
        </div>

        <form onSubmit={handleSubmit} className="space-y-4">
          <div className="space-y-2">
            <Label htmlFor="website-access-password">{t('Password')}</Label>
            <Input
              id="website-access-password"
              type="password"
              autoComplete="current-password"
              autoFocus
              value={password}
              onChange={(event) => {
                setPassword(event.target.value)
                if (error) setError(null)
              }}
              aria-invalid={Boolean(error)}
            />
            {error ? (
              <p className="text-[13px] text-destructive" role="alert">
                {error}
              </p>
            ) : null}
          </div>
          <Button type="submit" className="w-full">
            {t('Continue')}
          </Button>
        </form>
      </div>
    </div>
  )
}

/**
 * Full-screen soft-launch gate. Until the access cookie is set, the app shell
 * (layout, loaders, page content) is replaced by the password screen.
 *
 * A before-paint boot cover hides any SSR HTML so the site never flashes.
 */
export function WebsiteAccessGate({ children }: { children: ReactNode }) {
  const [locked, setLocked] = useState(false)

  useLayoutEffect(() => {
    if (
      !isWebsiteAccessEnabled(getRuntimeConfig().websiteAccess) ||
      !shouldShowWebsiteAccessGate(window.location.pathname) ||
      hasWebsiteAccessCookie()
    ) {
      removeBootCover()
      return
    }
    setLocked(true)
  }, [])

  if (locked) {
    return (
      <WebsiteAccessScreen
        onSuccess={() => {
          const params = new URLSearchParams(window.location.search)
          const redirect = params.get('redirect')
          const target =
            redirect?.startsWith('/') && !redirect.startsWith('//')
              ? redirect
              : window.location.pathname === '/access'
                ? '/'
                : null

          if (target) {
            window.location.replace(target)
            return
          }

          setLocked(false)
        }}
      />
    )
  }

  return children
}
