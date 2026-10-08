import * as React from 'react'

const MOBILE_BREAKPOINT = 768
const XL_BREAKPOINT = 1280

export function useIsMobile() {
  const [isMobile, setIsMobile] = React.useState<boolean | undefined>(undefined)

  React.useEffect(() => {
    const mql = window.matchMedia(`(max-width: ${MOBILE_BREAKPOINT - 1}px)`)
    const onChange = () => {
      setIsMobile(window.innerWidth < MOBILE_BREAKPOINT)
    }
    mql.addEventListener('change', onChange)
    setIsMobile(window.innerWidth < MOBILE_BREAKPOINT)
    return () => mql.removeEventListener('change', onChange)
  }, [])

  return !!isMobile
}

/** Tailwind `xl` breakpoint (1280px). Used for generator desktop column layout. */
export function useIsXlUp() {
  const [isXlUp, setIsXlUp] = React.useState(() =>
    typeof window !== 'undefined'
      ? window.matchMedia(`(min-width: ${XL_BREAKPOINT}px)`).matches
      : false,
  )

  React.useEffect(() => {
    const mql = window.matchMedia(`(min-width: ${XL_BREAKPOINT}px)`)
    const onChange = () => {
      setIsXlUp(mql.matches)
    }
    mql.addEventListener('change', onChange)
    setIsXlUp(mql.matches)
    return () => mql.removeEventListener('change', onChange)
  }, [])

  return isXlUp
}
