import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { formatPricingServiceList, pricingServices } from '@/lib/pricing/services'
import { cn } from '@/lib/utils'

export function PricingServicesAvatars() {
  return (
    <div className="mx-auto mt-20 max-w-3xl text-center sm:mt-24 lg:mt-28">
      <p className="text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
        All platform services included
      </p>
      <p className="mx-auto mt-2 max-w-2xl text-[13px] leading-6 text-muted-foreground sm:text-[14px] sm:leading-7">
        Every plan includes {formatPricingServiceList()}.
      </p>
      <ul
        className="mt-5 inline-flex items-center justify-center pl-0 sm:mt-6"
        aria-label="Included Appwrite services"
      >
        {pricingServices.map((service, index) => {
          const Icon = service.icon

          return (
            <li
              key={service.name}
              className={cn('relative shrink-0', index > 0 && '-ml-2 sm:-ml-2.5')}
              style={{ zIndex: index + 1 }}
            >
              <Tooltip>
                <TooltipTrigger asChild>
                  <div
                    className={cn(
                      'flex size-9 cursor-default items-center justify-center rounded-full border border-muted-foreground/6 bg-muted-foreground/[0.025] shadow-none backdrop-blur-sm transition-[border-color,opacity,transform] duration-300 dark:bg-muted/10',
                      'ring-1 ring-background',
                      'hover:z-10 hover:scale-105 hover:border-muted-foreground/10 hover:bg-muted-foreground/[0.04] sm:size-10',
                    )}
                    aria-label={service.name}
                  >
                    <Icon
                      className="size-4 text-muted-foreground/70 sm:size-[17px]"
                      strokeWidth={1.5}
                    />
                  </div>
                </TooltipTrigger>
                <TooltipContent side="bottom" className="text-[12px]">
                  {service.name}
                </TooltipContent>
              </Tooltip>
            </li>
          )
        })}
      </ul>
    </div>
  )
}
