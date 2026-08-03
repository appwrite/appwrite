import { Outlet } from '@tanstack/react-router'

/** Fullscreen agent shell — no console header/footer/tabs; child views own the chrome. */
export function Layout() {
  return (
    <div className="h-[100dvh] max-h-[100dvh] overflow-hidden bg-background">
      <Outlet />
    </div>
  )
}
