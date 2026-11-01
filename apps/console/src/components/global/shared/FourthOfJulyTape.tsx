import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

const TAPE_CLASS =
  'flex items-center overflow-hidden bg-[#0A3161] text-[10px] font-black uppercase leading-none tracking-[0.2em] text-[#F8FAFC] shadow-[0_1px_0_rgba(0,0,0,0.25),0_-1px_0_rgba(0,0,0,0.25)]'

const STRIPES = {
  backgroundImage:
    'repeating-linear-gradient(-45deg, #B91C1C 0 5px, #F8FAFC 5px 10px)',
}

function TapeSegment({ label }: { label: string }) {
  return (
    <span className="flex shrink-0 items-center gap-3 pe-3">
      <span className="h-full w-6 self-stretch" style={STRIPES} />
      <span className="whitespace-nowrap">{label}</span>
    </span>
  )
}

/** Festive tape band across the top of the terminal, scrolling slowly. */
export function FourthOfJulyTape() {
  const t = useT()
  const labels = Array.from({ length: 20 }, (_, index) =>
    index % 2 === 0 ? t('Happy 4th') : t('Independence Day'),
  )

  return (
    <div dir="ltr" className={cn(TAPE_CLASS, 'h-4 shrink-0')} aria-hidden>
      <div className="july-marquee flex h-full shrink-0">
        {[...labels, ...labels].map((label, index) => (
          <TapeSegment key={index} label={label} />
        ))}
      </div>
    </div>
  )
}

/** Short strip of tape stuck across the terminal's bottom corner; parent must be `relative`. */
export function FourthOfJulyCornerTape() {
  const t = useT()

  return (
    <div
      dir="ltr"
      className={cn(
        TAPE_CLASS,
        'pointer-events-none absolute -right-12 bottom-7 z-20 h-5 w-56 -rotate-[32deg] justify-center opacity-90',
      )}
      aria-hidden
    >
      <TapeSegment label={t('Happy 4th')} />
      <span className="h-full w-6 self-stretch" style={STRIPES} />
    </div>
  )
}
