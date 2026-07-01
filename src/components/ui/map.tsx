'use client'

import * as React from 'react'
import { createPortal } from 'react-dom'
import maplibregl from 'maplibre-gl'
import 'maplibre-gl/dist/maplibre-gl.css'
import { useTheme } from 'next-themes'
import { cn } from '@/lib/utils'

// Map context
const MapContext = React.createContext<{
  map: maplibregl.Map | null
  isLoaded: boolean
}>({
  map: null,
  isLoaded: false,
})

export function useMap() {
  const context = React.useContext(MapContext)
  if (!context) {
    throw new Error('useMap must be used within a Map component')
  }
  return context
}

// Country coordinates mapping
const COUNTRY_COORDINATES: Record<string, [number, number]> = {
  IN: [77.209, 20.5937], // India
  US: [-95.7129, 37.0902], // United States
  DE: [10.4515, 51.1657], // Germany
  GB: [-3.436, 55.3781], // United Kingdom
  NL: [5.2913, 52.1326], // Netherlands
  AU: [133.7751, -25.2744], // Australia
  CA: [-106.3468, 56.1304], // Canada
  SG: [103.8198, 1.3521], // Singapore
}

interface MapProps extends Omit<maplibregl.MapOptions, 'container' | 'style'> {
  children?: React.ReactNode
  styles?: {
    light?: string | maplibregl.StyleSpecification
    dark?: string | maplibregl.StyleSpecification
  }
  projection?: maplibregl.ProjectionSpecification
  /** When true, disables scroll-to-zoom so wheel events propagate (e.g. for page scroll) */
  disableScrollZoom?: boolean
}

export function Map({
  children,
  styles,
  projection,
  disableScrollZoom,
  ...mapOptions
}: MapProps) {
  const { theme } = useTheme()
  const mapContainerRef = React.useRef<HTMLDivElement>(null)
  const mapRef = React.useRef<maplibregl.Map | null>(null)
  const [isLoaded, setIsLoaded] = React.useState(false)

  // Default to CARTO basemap
  const defaultStyle = {
    light: 'https://basemaps.cartocdn.com/gl/positron-gl-style/style.json',
    dark: 'https://basemaps.cartocdn.com/gl/dark-matter-gl-style/style.json',
  }

  const mapStyle =
    styles?.[theme === 'dark' ? 'dark' : 'light'] ||
    defaultStyle[theme === 'dark' ? 'dark' : 'light']

  // State to track map instance for context
  const [mapInstance, setMapInstance] = React.useState<maplibregl.Map | null>(
    null,
  )

  React.useEffect(() => {
    if (!mapContainerRef.current) return

    let map: maplibregl.Map | null = null
    let resizeObserver: ResizeObserver | null = null
    let timeoutId: NodeJS.Timeout | null = null
    let mutationObserver: MutationObserver | null = null
    let retryCount = 0
    const maxRetries = 50 // Try for up to 5 seconds

    const checkVisibility = () => {
      if (!mapContainerRef.current) return false
      const rect = mapContainerRef.current.getBoundingClientRect()
      const computedStyle = window.getComputedStyle(mapContainerRef.current)
      return (
        computedStyle.display !== 'none' &&
        computedStyle.visibility !== 'hidden' &&
        mapContainerRef.current.offsetParent !== null &&
        rect.width > 0 &&
        rect.height > 0
      )
    }

    const initMap = () => {
      if (!mapContainerRef.current) return

      if (!checkVisibility()) {
        retryCount++
        if (retryCount < maxRetries) {
          timeoutId = setTimeout(() => {
            initMap()
          }, 100)
        }
        return
      }

      // Clean up existing map if any
      if (mapRef.current) {
        mapRef.current.remove()
        mapRef.current = null
        setMapInstance(null)
      }

      try {
        map = new maplibregl.Map({
          container: mapContainerRef.current,
          style: mapStyle,
          ...mapOptions,
          ...(projection && { projection }),
        })

        mapRef.current = map
        setMapInstance(map)

        map.on('load', () => {
          if (disableScrollZoom && map.scrollZoom) {
            map.scrollZoom.disable()
          }
          setIsLoaded(true)
          // Force resize after load
          setTimeout(() => {
            if (map) {
              map.resize()
            }
          }, 50)
        })

        map.on('error', (e) => {
          console.error('Map error:', e)
        })

        // Resize map when container dimensions change
        resizeObserver = new ResizeObserver(() => {
          if (map && mapContainerRef.current && checkVisibility()) {
            requestAnimationFrame(() => {
              map.resize()
            })
          }
        })

        if (mapContainerRef.current) {
          resizeObserver.observe(mapContainerRef.current)
        }

        // Watch for visibility changes (e.g., when tab becomes visible)
        if (mapContainerRef.current.parentElement) {
          mutationObserver = new MutationObserver(() => {
            if (checkVisibility() && map && !isLoaded) {
              // Container just became visible, try to initialize
              setTimeout(() => {
                if (map) {
                  map.resize()
                }
              }, 100)
            }
          })

          mutationObserver.observe(mapContainerRef.current.parentElement, {
            attributes: true,
            attributeFilter: ['style', 'class'],
            childList: false,
            subtree: false,
          })
        }
      } catch (error) {
        console.error('Error initializing map:', error)
      }
    }

    initMap()

    return () => {
      if (timeoutId) {
        clearTimeout(timeoutId)
      }
      if (resizeObserver) {
        resizeObserver.disconnect()
      }
      if (mutationObserver) {
        mutationObserver.disconnect()
      }
      if (mapRef.current) {
        mapRef.current.remove()
        mapRef.current = null
        setMapInstance(null)
      }
      setIsLoaded(false)
    }
  }, [
    mapStyle,
    theme,
    disableScrollZoom,
    JSON.stringify(mapOptions),
    projection,
  ])

  return (
    <MapContext.Provider value={{ map: mapInstance, isLoaded }}>
      <div ref={mapContainerRef} className="h-full w-full" />
      {children}
    </MapContext.Provider>
  )
}

// Marker context for popup/tooltip
const MarkerContext = React.createContext<{
  marker: maplibregl.Marker | null
  popup: maplibregl.Popup | null
  setPopup: (popup: maplibregl.Popup | null) => void
}>({
  marker: null,
  popup: null,
  setPopup: () => {},
})

interface MapMarkerProps extends Omit<maplibregl.MarkerOptions, 'element'> {
  longitude: number
  latitude: number
  children?: React.ReactNode
  onClick?: (e: MouseEvent) => void
  onMouseEnter?: (e: MouseEvent) => void
  onMouseLeave?: (e: MouseEvent) => void
  onDragStart?: (lngLat: { lng: number; lat: number }) => void
  onDrag?: (lngLat: { lng: number; lat: number }) => void
  onDragEnd?: (lngLat: { lng: number; lat: number }) => void
}

export function MapMarker({
  longitude,
  latitude,
  children,
  onClick,
  onMouseEnter,
  onMouseLeave,
  onDragStart,
  onDrag,
  onDragEnd,
  ...markerOptions
}: MapMarkerProps) {
  const { map, isLoaded } = useMap()
  const markerRef = React.useRef<maplibregl.Marker | null>(null)
  const elementRef = React.useRef<HTMLDivElement | null>(null)
  const popupRef = React.useRef<maplibregl.Popup | null>(null)
  const [popup, setPopup] = React.useState<maplibregl.Popup | null>(null)

  React.useEffect(() => {
    if (!map || !isLoaded) return

    const element = document.createElement('div')
    elementRef.current = element

    const marker = new maplibregl.Marker({
      element,
      ...markerOptions,
    })
      .setLngLat([longitude, latitude])
      .addTo(map)

    markerRef.current = marker

    // Enable popup on click by default if no onClick handler
    if (!onClick) {
      element.style.cursor = 'pointer'
    }

    if (onClick) {
      element.addEventListener('click', onClick as any)
    }
    if (onMouseEnter) {
      element.addEventListener('mouseenter', onMouseEnter as any)
    }
    if (onMouseLeave) {
      element.addEventListener('mouseleave', onMouseLeave as any)
    }
    if (onDragStart) {
      marker.on('dragstart', () => {
        const lngLat = marker.getLngLat()
        onDragStart({ lng: lngLat.lng, lat: lngLat.lat })
      })
    }
    if (onDrag) {
      marker.on('drag', () => {
        const lngLat = marker.getLngLat()
        onDrag({ lng: lngLat.lng, lat: lngLat.lat })
      })
    }
    if (onDragEnd) {
      marker.on('dragend', () => {
        const lngLat = marker.getLngLat()
        onDragEnd({ lng: lngLat.lng, lat: lngLat.lat })
      })
    }

    return () => {
      marker.remove()
      if (popupRef.current) {
        popupRef.current.remove()
      }
      if (onClick) {
        element.removeEventListener('click', onClick as any)
      }
      if (onMouseEnter) {
        element.removeEventListener('mouseenter', onMouseEnter as any)
      }
      if (onMouseLeave) {
        element.removeEventListener('mouseleave', onMouseLeave as any)
      }
    }
  }, [map, isLoaded, longitude, latitude, onClick, onMouseEnter, onMouseLeave])

  if (!elementRef.current) return null

  return (
    <MarkerContext.Provider
      value={{
        marker: markerRef.current,
        popup: popupRef.current,
        setPopup: (p) => {
          popupRef.current = p
          setPopup(p)
        },
      }}
    >
      {createPortal(
        <div className="relative">{children || <DefaultMarker />}</div>,
        elementRef.current,
      )}
    </MarkerContext.Provider>
  )
}

function DefaultMarker() {
  return (
    <div className="h-3 w-3 rounded-full border-2 border-white bg-blue-500" />
  )
}

interface MarkerContentProps {
  children?: React.ReactNode
  className?: string
}

export function MarkerContent({ children, className }: MarkerContentProps) {
  return <div className={cn('relative', className)}>{children}</div>
}

interface MarkerPopupProps extends Omit<
  maplibregl.PopupOptions,
  'className' | 'closeButton'
> {
  children?: React.ReactNode
  className?: string
  closeButton?: boolean
}

export function MarkerPopup({
  children,
  className,
  closeButton = false,
  ...popupOptions
}: MarkerPopupProps) {
  const { marker, setPopup } = React.useContext(MarkerContext)
  const containerRef = React.useRef<HTMLDivElement | null>(null)
  const popupRef = React.useRef<maplibregl.Popup | null>(null)

  React.useEffect(() => {
    if (!marker) return

    // Create container for popup content
    const container = document.createElement('div')
    container.className = cn('', className)
    containerRef.current = container

    // Create popup and attach to marker
    const popup = new maplibregl.Popup({
      closeButton,
      ...popupOptions,
    })

    popupRef.current = popup
    marker.setPopup(popup)
    setPopup(popup)

    // Set DOM content after a brief delay to ensure container is ready
    const timeoutId = setTimeout(() => {
      if (containerRef.current) {
        popup.setDOMContent(containerRef.current)
      }
    }, 0)

    return () => {
      clearTimeout(timeoutId)
      if (popup) {
        popup.remove()
      }
      setPopup(null)
    }
  }, [marker, closeButton, className, setPopup])

  // Update popup content when children change
  React.useEffect(() => {
    if (containerRef.current && popupRef.current) {
      popupRef.current.setDOMContent(containerRef.current)
    }
  }, [children])

  // Render children into the container
  if (containerRef.current) {
    return createPortal(children, containerRef.current)
  }

  return null
}

interface MapControlsProps {
  position?: 'top-left' | 'top-right' | 'bottom-left' | 'bottom-right'
  showZoom?: boolean
  showCompass?: boolean
  showLocate?: boolean
  showFullscreen?: boolean
  className?: string
  onLocate?: (coords: { longitude: number; latitude: number }) => void
}

export function MapControls({
  position = 'bottom-right',
  showZoom = true,
  showCompass = false,
  showLocate = false,
  showFullscreen = false,
  className,
  onLocate,
}: MapControlsProps) {
  const { map, isLoaded } = useMap()

  if (!map || !isLoaded) return null

  const positionClasses = {
    'top-left': 'top-2 start-2',
    'top-right': 'top-2 end-2',
    'bottom-left': 'bottom-2 start-2',
    'bottom-right': 'bottom-2 end-2',
  }

  return (
    <div
      className={cn(
        'absolute z-10 flex flex-col gap-1',
        positionClasses[position],
        className,
      )}
    >
      {showZoom && (
        <>
          <button
            onClick={() => map.zoomIn()}
            className="flex h-8 w-8 items-center justify-center rounded border border-border bg-background hover:bg-accent"
          >
            <span className="text-sm">+</span>
          </button>
          <button
            onClick={() => map.zoomOut()}
            className="flex h-8 w-8 items-center justify-center rounded border border-border bg-background hover:bg-accent"
          >
            <span className="text-sm">−</span>
          </button>
        </>
      )}
    </div>
  )
}

// Export country coordinates for use in analytics
export { COUNTRY_COORDINATES }
