import { useState, useMemo } from 'react'
import {
  LayoutDashboard,
  Component,
  Layers,
  Navigation,
  FormInput,
  AlertCircle,
  Table,
  Sparkles,
  Info,
} from 'lucide-react'
import {
  Sidebar,
  SidebarContent,
  SidebarGroup,
  SidebarGroupContent,
  SidebarGroupLabel,
  SidebarMenu,
  SidebarMenuButton,
  SidebarProvider,
} from '@/components/ui/sidebar'
import { Tabs, TabsList, TabsTrigger, TabsContent } from '@/components/ui/tabs'
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card'
import { Separator } from '@/components/ui/separator'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Badge } from '@/components/ui/badge'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Progress } from '@/components/ui/progress'
import { Skeleton } from '@/components/ui/skeleton'
import { Avatar, AvatarFallback } from '@/components/ui/avatar'
import { Switch } from '@/components/ui/switch'
import { Checkbox } from '@/components/ui/checkbox'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
import { Slider } from '@/components/ui/slider'
import {
  Breadcrumb,
  BreadcrumbItem,
  BreadcrumbLink,
  BreadcrumbList,
  BreadcrumbPage,
  BreadcrumbSeparator,
} from '@/components/ui/breadcrumb'
import {
  Pagination,
  PaginationContent,
  PaginationItem,
  PaginationLink,
  PaginationNext,
  PaginationPrevious,
} from '@/components/ui/pagination'
import {
  Accordion,
  AccordionContent,
  AccordionItem,
  AccordionTrigger,
} from '@/components/ui/accordion'
import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from '@/components/ui/collapsible'
import {
  Table as TableComponent,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog'
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  AlertDialogTrigger,
} from '@/components/ui/alert-dialog'
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
  SheetTrigger,
} from '@/components/ui/sheet'
import {
  Drawer,
  DrawerClose,
  DrawerContent,
  DrawerDescription,
  DrawerFooter,
  DrawerHeader,
  DrawerTitle,
  DrawerTrigger,
} from '@/components/ui/drawer'
import {
  HoverCard,
  HoverCardContent,
  HoverCardTrigger,
} from '@/components/ui/hover-card'
import {
  ContextMenu,
  ContextMenuContent,
  ContextMenuItem,
  ContextMenuTrigger,
} from '@/components/ui/context-menu'
import { Calendar } from '@/components/ui/calendar'
import { Toggle } from '@/components/ui/toggle'
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group'
import { AspectRatio } from '@/components/ui/aspect-ratio'
import { ScrollArea } from '@/components/ui/scroll-area'
import {
  ResizablePanelGroup,
  ResizablePanel,
  ResizableHandle,
} from '@/components/ui/resizable'
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
} from '@/components/ui/command'
import {
  InputOTP,
  InputOTPGroup,
  InputOTPSlot,
} from '@/components/ui/input-otp'
import { IdInput } from '@/components/ui/id-input'
import { cn } from '@/lib/utils'

// Component categories
type Category =
  | 'all'
  | 'layout'
  | 'navigation'
  | 'forms'
  | 'overlays'
  | 'feedback'
  | 'data-display'

interface ComponentInfo {
  name: string
  file: string
  category: Category
  description?: string
}

const UI_COMPONENTS: ComponentInfo[] = [
  // Layout
  {
    name: 'Card',
    file: 'card',
    category: 'layout',
    description: 'Container for content sections',
  },
  {
    name: 'Separator',
    file: 'separator',
    category: 'layout',
    description: 'Visual divider between sections',
  },
  {
    name: 'Aspect Ratio',
    file: 'aspect-ratio',
    category: 'layout',
    description: 'Maintain aspect ratio for media',
  },
  {
    name: 'Resizable',
    file: 'resizable',
    category: 'layout',
    description: 'Resizable panels and containers',
  },
  {
    name: 'Scroll Area',
    file: 'scroll-area',
    category: 'layout',
    description: 'Custom scrollable container',
  },
  {
    name: 'Skeleton',
    file: 'skeleton',
    category: 'layout',
    description: 'Loading placeholder component',
  },

  // Navigation
  {
    name: 'Breadcrumb',
    file: 'breadcrumb',
    category: 'navigation',
    description: 'Navigation breadcrumb trail',
  },
  {
    name: 'Navigation Menu',
    file: 'navigation-menu',
    category: 'navigation',
    description: 'Main navigation menu',
  },
  {
    name: 'Menubar',
    file: 'menubar',
    category: 'navigation',
    description: 'Application menubar',
  },
  {
    name: 'Sidebar',
    file: 'sidebar',
    category: 'navigation',
    description: 'Collapsible sidebar component',
  },
  {
    name: 'Tabs',
    file: 'tabs',
    category: 'navigation',
    description: 'Tabbed interface',
  },
  {
    name: 'Pagination',
    file: 'pagination',
    category: 'navigation',
    description: 'Page navigation controls',
  },

  // Forms
  {
    name: 'Button',
    file: 'button',
    category: 'forms',
    description: 'Interactive button element',
  },
  {
    name: 'Input',
    file: 'input',
    category: 'forms',
    description: 'Text input field',
  },
  {
    name: 'Textarea',
    file: 'textarea',
    category: 'forms',
    description: 'Multi-line text input',
  },
  {
    name: 'Select',
    file: 'select',
    category: 'forms',
    description: 'Dropdown selection',
  },
  {
    name: 'Checkbox',
    file: 'checkbox',
    category: 'forms',
    description: 'Checkbox input',
  },
  {
    name: 'Radio Group',
    file: 'radio-group',
    category: 'forms',
    description: 'Radio button group',
  },
  {
    name: 'Switch',
    file: 'switch',
    category: 'forms',
    description: 'Toggle switch',
  },
  {
    name: 'Slider',
    file: 'slider',
    category: 'forms',
    description: 'Range slider input',
  },
  {
    name: 'Form',
    file: 'form',
    category: 'forms',
    description: 'Form wrapper with validation',
  },
  {
    name: 'Label',
    file: 'label',
    category: 'forms',
    description: 'Form field label',
  },
  {
    name: 'Input OTP',
    file: 'input-otp',
    category: 'forms',
    description: 'OTP code input',
  },
  {
    name: 'ID Input',
    file: 'id-input',
    category: 'forms',
    description: 'Custom ID input field',
  },

  // Overlays
  {
    name: 'Dialog',
    file: 'dialog',
    category: 'overlays',
    description: 'Modal dialog window',
  },
  {
    name: 'Alert Dialog',
    file: 'alert-dialog',
    category: 'overlays',
    description: 'Confirmation dialog',
  },
  {
    name: 'Sheet',
    file: 'sheet',
    category: 'overlays',
    description: 'Slide-out panel',
  },
  {
    name: 'Drawer',
    file: 'drawer',
    category: 'overlays',
    description: 'Mobile drawer component',
  },
  {
    name: 'Popover',
    file: 'popover',
    category: 'overlays',
    description: 'Popover tooltip',
  },
  {
    name: 'Hover Card',
    file: 'hover-card',
    category: 'overlays',
    description: 'Hover-triggered card',
  },
  {
    name: 'Tooltip',
    file: 'tooltip',
    category: 'overlays',
    description: 'Contextual tooltip',
  },
  {
    name: 'Context Menu',
    file: 'context-menu',
    category: 'overlays',
    description: 'Right-click menu',
  },
  {
    name: 'Dropdown Menu',
    file: 'dropdown-menu',
    category: 'overlays',
    description: 'Dropdown menu',
  },

  // Feedback
  {
    name: 'Alert',
    file: 'alert',
    category: 'feedback',
    description: 'Alert notification',
  },
  {
    name: 'Badge',
    file: 'badge',
    category: 'feedback',
    description: 'Status badge',
  },
  {
    name: 'Progress',
    file: 'progress',
    category: 'feedback',
    description: 'Progress indicator',
  },
  {
    name: 'Sonner',
    file: 'sonner',
    category: 'feedback',
    description: 'Toast notifications',
  },
  {
    name: 'Loader',
    file: 'loader',
    category: 'feedback',
    description: 'Loading spinner',
  },

  // Data Display
  {
    name: 'Table',
    file: 'table',
    category: 'data-display',
    description: 'Data table component',
  },
  {
    name: 'Accordion',
    file: 'accordion',
    category: 'data-display',
    description: 'Collapsible content sections',
  },
  {
    name: 'Collapsible',
    file: 'collapsible',
    category: 'data-display',
    description: 'Expandable content',
  },
  {
    name: 'Chart',
    file: 'chart',
    category: 'data-display',
    description: 'Chart visualization',
  },
  {
    name: 'Avatar',
    file: 'avatar',
    category: 'data-display',
    description: 'User avatar image',
  },
  {
    name: 'Calendar',
    file: 'calendar',
    category: 'data-display',
    description: 'Date picker calendar',
  },
  {
    name: 'Carousel',
    file: 'carousel',
    category: 'data-display',
    description: 'Image/content carousel',
  },

  // Custom
  {
    name: 'Upgrade Curtain',
    file: 'upgrade-curtain',
    category: 'layout',
    description: 'Feature upgrade overlay',
  },
  {
    name: 'Command',
    file: 'command',
    category: 'navigation',
    description: 'Command palette',
  },
  {
    name: 'Toggle',
    file: 'toggle',
    category: 'forms',
    description: 'Toggle button',
  },
  {
    name: 'Toggle Group',
    file: 'toggle-group',
    category: 'forms',
    description: 'Toggle button group',
  },
]

const CATEGORIES: { id: Category; label: string; icon: typeof Component }[] = [
  { id: 'all', label: 'All Components', icon: Component },
  { id: 'layout', label: 'Layout', icon: Layers },
  { id: 'navigation', label: 'Navigation', icon: Navigation },
  { id: 'forms', label: 'Forms', icon: FormInput },
  { id: 'overlays', label: 'Overlays', icon: Sparkles },
  { id: 'feedback', label: 'Feedback', icon: AlertCircle },
  { id: 'data-display', label: 'Data Display', icon: Table },
]

export function View() {
  const [selectedCategory, setSelectedCategory] = useState<Category>('all')

  const filteredComponents = useMemo(() => {
    if (selectedCategory === 'all') {
      return UI_COMPONENTS
    }
    return UI_COMPONENTS.filter((comp) => comp.category === selectedCategory)
  }, [selectedCategory])

  const categories = useMemo(() => {
    const cats = CATEGORIES.map((cat) => ({
      ...cat,
      count: UI_COMPONENTS.filter((comp) =>
        cat.id === 'all' ? true : comp.category === cat.id,
      ).length,
    }))
    return cats
  }, [])

  return (
    <SidebarProvider>
      <div className="flex h-dvh w-full">
        <Sidebar className="border-e">
          <SidebarContent>
            <SidebarGroup>
              <SidebarGroupLabel className="flex items-center gap-2">
                <LayoutDashboard className="h-4 w-4" />
                UI Components
              </SidebarGroupLabel>
              <SidebarGroupContent>
                <SidebarMenu>
                  {categories.map((category) => (
                    <SidebarMenuButton
                      key={category.id}
                      onClick={() => setSelectedCategory(category.id)}
                      className={cn(
                        'w-full justify-between',
                        selectedCategory === category.id && 'bg-accent',
                      )}
                    >
                      <div className="flex items-center gap-2">
                        <category.icon className="h-4 w-4" />
                        <span>{category.label}</span>
                      </div>
                      <span className="text-xs text-muted-foreground">
                        {category.count}
                      </span>
                    </SidebarMenuButton>
                  ))}
                </SidebarMenu>
              </SidebarGroupContent>
            </SidebarGroup>
          </SidebarContent>
        </Sidebar>

        <div className="flex-1 overflow-auto">
          <div className="container mx-auto p-6">
            <div className="mb-6">
              <h1 className="text-3xl font-bold mb-2">
                UI Components Showcase
              </h1>
              <p className="text-muted-foreground">
                Browse and preview all available UI components in the design
                system
              </p>
            </div>

            <Separator className="mb-6" />

            <Tabs
              value={selectedCategory}
              onValueChange={(v) => setSelectedCategory(v as Category)}
            >
              <TabsList className="mb-6">
                {categories.map((category) => (
                  <TabsTrigger key={category.id} value={category.id}>
                    {category.label} ({category.count})
                  </TabsTrigger>
                ))}
              </TabsList>

              <TabsContent value={selectedCategory} className="mt-0">
                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                  {filteredComponents.map((component) => (
                    <ComponentCard key={component.file} component={component} />
                  ))}
                </div>
              </TabsContent>
            </Tabs>
          </div>
        </div>
      </div>
    </SidebarProvider>
  )
}

interface ComponentCardProps {
  component: ComponentInfo
}

function ComponentCard({ component }: ComponentCardProps) {
  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2">
          <Component className="h-4 w-4" />
          {component.name}
        </CardTitle>
        {component.description && (
          <CardDescription>{component.description}</CardDescription>
        )}
      </CardHeader>
      <CardContent>
        <div className="space-y-2">
          <div className="text-sm">
            <span className="text-muted-foreground">File: </span>
            <code className="text-xs bg-muted px-1.5 py-0.5 rounded">
              {component.file}.tsx
            </code>
          </div>
          <div className="text-sm">
            <span className="text-muted-foreground">Category: </span>
            <span className="capitalize">{component.category}</span>
          </div>
          <div className="pt-2">
            <ComponentPreview component={component} />
          </div>
        </div>
      </CardContent>
    </Card>
  )
}

function ComponentPreview({ component }: { component: ComponentInfo }) {
  const previews: Record<string, React.ReactNode> = {
    // Forms
    button: (
      <div className="flex flex-wrap gap-2">
        <Button>Default</Button>
        <Button variant="secondary">Secondary</Button>
        <Button variant="outline">Outline</Button>
        <Button variant="destructive">Destructive</Button>
        <Button variant="ghost">Ghost</Button>
        <Button variant="link">Link</Button>
      </div>
    ),
    input: (
      <div className="space-y-2">
        <Input placeholder="Enter text..." />
        <Input type="email" placeholder="email@example.com" />
      </div>
    ),
    textarea: <Textarea placeholder="Type your message here..." />,
    select: (
      <Select>
        <SelectTrigger className="w-full">
          <SelectValue placeholder="Select an option" />
        </SelectTrigger>
        <SelectContent>
          <SelectItem value="option1">Option 1</SelectItem>
          <SelectItem value="option2">Option 2</SelectItem>
          <SelectItem value="option3">Option 3</SelectItem>
        </SelectContent>
      </Select>
    ),
    checkbox: (
      <div className="flex items-center gap-2">
        <Checkbox id="preview-check" />
        <Label htmlFor="preview-check">Accept terms</Label>
      </div>
    ),
    'radio-group': (
      <RadioGroup defaultValue="option1">
        <div className="flex items-center gap-2">
          <RadioGroupItem value="option1" id="r1" />
          <Label htmlFor="r1">Option 1</Label>
        </div>
        <div className="flex items-center gap-2">
          <RadioGroupItem value="option2" id="r2" />
          <Label htmlFor="r2">Option 2</Label>
        </div>
      </RadioGroup>
    ),
    switch: (
      <div className="flex items-center gap-2">
        <Switch />
        <Label>Enable notifications</Label>
      </div>
    ),
    slider: (
      <div className="space-y-2">
        <Slider defaultValue={[50]} max={100} step={1} />
        <Slider defaultValue={[25, 75]} max={100} step={1} />
      </div>
    ),
    label: (
      <div className="space-y-2">
        <Label>Label text</Label>
        <Label htmlFor="input-id">Label with htmlFor</Label>
      </div>
    ),
    toggle: (
      <div className="flex gap-2">
        <Toggle>Toggle</Toggle>
        <Toggle variant="outline">Outline</Toggle>
      </div>
    ),
    'toggle-group': (
      <ToggleGroup type="single">
        <ToggleGroupItem value="a">A</ToggleGroupItem>
        <ToggleGroupItem value="b">B</ToggleGroupItem>
        <ToggleGroupItem value="c">C</ToggleGroupItem>
      </ToggleGroup>
    ),

    // Layout
    card: (
      <Card>
        <CardHeader>
          <CardTitle>Card Title</CardTitle>
          <CardDescription>Card description text</CardDescription>
        </CardHeader>
        <CardContent>
          <p className="text-sm">Card content goes here</p>
        </CardContent>
      </Card>
    ),
    separator: <Separator />,
    'aspect-ratio': (
      <div className="w-full max-w-xs">
        <AspectRatio
          ratio={16 / 9}
          className="bg-muted rounded-md flex items-center justify-center"
        >
          <span className="text-sm text-muted-foreground">16:9</span>
        </AspectRatio>
      </div>
    ),
    'scroll-area': (
      <ScrollArea className="h-24 w-full rounded border p-4">
        <div className="space-y-2">
          {Array.from({ length: 10 }).map((_, i) => (
            <div key={i} className="text-sm">
              Item {i + 1}
            </div>
          ))}
        </div>
      </ScrollArea>
    ),
    resizable: (
      <ResizablePanelGroup
        direction="horizontal"
        className="max-w-md rounded-lg border"
      >
        <ResizablePanel defaultSize={50}>
          <div className="flex h-full items-center justify-center p-4">
            <span className="text-sm">Panel 1</span>
          </div>
        </ResizablePanel>
        <ResizableHandle />
        <ResizablePanel defaultSize={50}>
          <div className="flex h-full items-center justify-center p-4">
            <span className="text-sm">Panel 2</span>
          </div>
        </ResizablePanel>
      </ResizablePanelGroup>
    ),
    skeleton: (
      <div className="space-y-2">
        <Skeleton className="h-4 w-full" />
        <Skeleton className="h-4 w-3/4" />
        <Skeleton className="h-4 w-1/2" />
      </div>
    ),

    // Navigation
    breadcrumb: (
      <Breadcrumb>
        <BreadcrumbList>
          <BreadcrumbItem>
            <BreadcrumbLink href="#">Home</BreadcrumbLink>
          </BreadcrumbItem>
          <BreadcrumbSeparator />
          <BreadcrumbItem>
            <BreadcrumbLink href="#">Components</BreadcrumbLink>
          </BreadcrumbItem>
          <BreadcrumbSeparator />
          <BreadcrumbItem>
            <BreadcrumbPage>Breadcrumb</BreadcrumbPage>
          </BreadcrumbItem>
        </BreadcrumbList>
      </Breadcrumb>
    ),
    tabs: (
      <Tabs defaultValue="tab1">
        <TabsList>
          <TabsTrigger value="tab1">Tab 1</TabsTrigger>
          <TabsTrigger value="tab2">Tab 2</TabsTrigger>
          <TabsTrigger value="tab3">Tab 3</TabsTrigger>
        </TabsList>
        <TabsContent value="tab1" className="mt-2 text-sm">
          Content for Tab 1
        </TabsContent>
        <TabsContent value="tab2" className="mt-2 text-sm">
          Content for Tab 2
        </TabsContent>
        <TabsContent value="tab3" className="mt-2 text-sm">
          Content for Tab 3
        </TabsContent>
      </Tabs>
    ),
    pagination: (
      <Pagination>
        <PaginationContent>
          <PaginationItem>
            <PaginationPrevious href="#" />
          </PaginationItem>
          <PaginationItem>
            <PaginationLink href="#">1</PaginationLink>
          </PaginationItem>
          <PaginationItem>
            <PaginationLink href="#" isActive>
              2
            </PaginationLink>
          </PaginationItem>
          <PaginationItem>
            <PaginationLink href="#">3</PaginationLink>
          </PaginationItem>
          <PaginationItem>
            <PaginationNext href="#" />
          </PaginationItem>
        </PaginationContent>
      </Pagination>
    ),
    command: (
      <Command className="rounded-lg border">
        <CommandInput placeholder="Type a command or search..." />
        <CommandList>
          <CommandEmpty>No results found.</CommandEmpty>
          <CommandGroup heading="Suggestions">
            <CommandItem>
              <span>Calendar</span>
            </CommandItem>
            <CommandItem>
              <span>Search Emoji</span>
            </CommandItem>
            <CommandItem>
              <span>Calculator</span>
            </CommandItem>
          </CommandGroup>
        </CommandList>
      </Command>
    ),

    // Overlays
    dialog: (
      <Dialog>
        <DialogTrigger asChild>
          <Button>Open Dialog</Button>
        </DialogTrigger>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Dialog Title</DialogTitle>
            <DialogDescription>Dialog description text</DialogDescription>
          </DialogHeader>
          <p className="text-sm">Dialog content goes here</p>
        </DialogContent>
      </Dialog>
    ),
    'alert-dialog': (
      <AlertDialog>
        <AlertDialogTrigger asChild>
          <Button variant="outline">Open Alert</Button>
        </AlertDialogTrigger>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Are you sure?</AlertDialogTitle>
            <AlertDialogDescription>
              This action cannot be undone.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction>Continue</AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    ),
    sheet: (
      <Sheet>
        <SheetTrigger asChild>
          <Button>Open Sheet</Button>
        </SheetTrigger>
        <SheetContent>
          <SheetHeader>
            <SheetTitle>Sheet Title</SheetTitle>
            <SheetDescription>Sheet description</SheetDescription>
          </SheetHeader>
          <p className="text-sm mt-4">Sheet content</p>
        </SheetContent>
      </Sheet>
    ),
    drawer: (
      <Drawer>
        <DrawerTrigger asChild>
          <Button>Open Drawer</Button>
        </DrawerTrigger>
        <DrawerContent>
          <DrawerHeader>
            <DrawerTitle>Drawer Title</DrawerTitle>
            <DrawerDescription>Drawer description</DrawerDescription>
          </DrawerHeader>
          <div className="p-4">
            <p className="text-sm">Drawer content</p>
          </div>
          <DrawerFooter>
            <Button>Submit</Button>
            <DrawerClose asChild>
              <Button variant="outline">Cancel</Button>
            </DrawerClose>
          </DrawerFooter>
        </DrawerContent>
      </Drawer>
    ),
    popover: (
      <Popover>
        <PopoverTrigger asChild>
          <Button variant="outline">Open Popover</Button>
        </PopoverTrigger>
        <PopoverContent>
          <div className="space-y-2">
            <h4 className="font-medium text-sm">Popover Title</h4>
            <p className="text-sm text-muted-foreground">
              Popover content text
            </p>
          </div>
        </PopoverContent>
      </Popover>
    ),
    'hover-card': (
      <HoverCard>
        <HoverCardTrigger asChild>
          <Button variant="link">Hover me</Button>
        </HoverCardTrigger>
        <HoverCardContent>
          <div className="space-y-1">
            <h4 className="text-sm font-semibold">Hover Card</h4>
            <p className="text-sm text-muted-foreground">Hover card content</p>
          </div>
        </HoverCardContent>
      </HoverCard>
    ),
    tooltip: (
      <TooltipProvider>
        <Tooltip>
          <TooltipTrigger asChild>
            <Button variant="outline" size="icon" aria-label="Show tooltip">
              <Info className="h-4 w-4" />
            </Button>
          </TooltipTrigger>
          <TooltipContent>
            <p>Tooltip content</p>
          </TooltipContent>
        </Tooltip>
      </TooltipProvider>
    ),
    'context-menu': (
      <ContextMenu>
        <ContextMenuTrigger className="flex h-24 w-full items-center justify-center rounded-md border border-dashed">
          Right click here
        </ContextMenuTrigger>
        <ContextMenuContent>
          <ContextMenuItem>Copy</ContextMenuItem>
          <ContextMenuItem>Paste</ContextMenuItem>
          <ContextMenuItem>Delete</ContextMenuItem>
        </ContextMenuContent>
      </ContextMenu>
    ),
    'dropdown-menu': (
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <Button variant="outline">Open Menu</Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent>
          <DropdownMenuLabel>My Account</DropdownMenuLabel>
          <DropdownMenuSeparator />
          <DropdownMenuItem>Profile</DropdownMenuItem>
          <DropdownMenuItem>Settings</DropdownMenuItem>
          <DropdownMenuItem>Logout</DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>
    ),

    // Feedback
    alert: (
      <div className="space-y-2">
        <Alert>
          <AlertDescription>This is a default alert message.</AlertDescription>
        </Alert>
        <Alert variant="destructive">
          <AlertDescription>
            This is a destructive alert message.
          </AlertDescription>
        </Alert>
      </div>
    ),
    badge: (
      <div className="flex flex-wrap gap-2">
        <Badge>Default</Badge>
        <Badge variant="secondary">Secondary</Badge>
        <Badge variant="outline">Outline</Badge>
        <Badge variant="destructive">Destructive</Badge>
      </div>
    ),
    progress: (
      <div className="space-y-2">
        <Progress value={33} />
        <Progress value={66} />
        <Progress value={100} />
      </div>
    ),

    // Data Display
    table: (
      <div className="rounded-md border">
        <TableComponent>
          <TableHeader>
            <TableRow className="hover:bg-transparent border-b border-border">
              <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                Name
              </TableHead>
              <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                Status
              </TableHead>
              <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                Role
              </TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            <TableRow>
              <TableCell className="px-4 py-3">Walter O&apos;Brien</TableCell>
              <TableCell className="px-4 py-3">Active</TableCell>
              <TableCell className="px-4 py-3">Admin</TableCell>
            </TableRow>
            <TableRow>
              <TableCell className="px-4 py-3">Jane Smith</TableCell>
              <TableCell className="px-4 py-3">Active</TableCell>
              <TableCell className="px-4 py-3">User</TableCell>
            </TableRow>
          </TableBody>
        </TableComponent>
      </div>
    ),
    accordion: (
      <Accordion type="single" collapsible>
        <AccordionItem value="item-1">
          <AccordionTrigger>Is it accessible?</AccordionTrigger>
          <AccordionContent>
            Yes. It adheres to the WAI-ARIA design pattern.
          </AccordionContent>
        </AccordionItem>
        <AccordionItem value="item-2">
          <AccordionTrigger>Is it styled?</AccordionTrigger>
          <AccordionContent>
            Yes. It comes with default styles that match the design system.
          </AccordionContent>
        </AccordionItem>
      </Accordion>
    ),
    collapsible: (
      <Collapsible>
        <CollapsibleTrigger asChild>
          <Button variant="ghost" size="sm">
            Toggle
          </Button>
        </CollapsibleTrigger>
        <CollapsibleContent className="mt-2">
          <div className="rounded-md border p-4 text-sm">
            Collapsible content goes here
          </div>
        </CollapsibleContent>
      </Collapsible>
    ),
    avatar: (
      <div className="flex gap-2">
        <Avatar>
          <AvatarFallback>JD</AvatarFallback>
        </Avatar>
        <Avatar>
          <AvatarFallback>AB</AvatarFallback>
        </Avatar>
        <Avatar>
          <AvatarFallback>CD</AvatarFallback>
        </Avatar>
      </div>
    ),
    calendar: <Calendar mode="single" className="rounded-md border" />,

    // Additional form components
    'input-otp': (
      <InputOTP maxLength={6}>
        <InputOTPGroup>
          <InputOTPSlot index={0} />
          <InputOTPSlot index={1} />
          <InputOTPSlot index={2} />
          <InputOTPSlot index={3} />
          <InputOTPSlot index={4} />
          <InputOTPSlot index={5} />
        </InputOTPGroup>
      </InputOTP>
    ),
    'id-input': <IdInput placeholder="Enter custom ID" />,
  }

  const preview = previews[component.file]

  if (preview) {
    return (
      <div className="border rounded-md p-4 bg-muted/30 min-h-[100px] flex items-center justify-center">
        <div className="w-full">{preview}</div>
      </div>
    )
  }

  // For components without previews, show informative placeholders
  const complexComponents: Record<string, string> = {
    form: 'Requires react-hook-form setup',
    'navigation-menu': 'Complex navigation component',
    menubar: 'Application menubar component',
    sidebar: 'Used in this view',
    chart: 'Requires chart library setup',
    carousel: 'Image/content carousel',
    sonner: 'Toast notifications (requires Toaster provider)',
    loader: 'Fullscreen loading component',
    'upgrade-curtain': 'Feature upgrade overlay',
  }

  const complexNote = complexComponents[component.file]

  return (
    <div className="border rounded-md p-4 bg-muted/30 min-h-[100px] flex items-center justify-center">
      <div className="text-center space-y-1">
        <p className="text-sm text-muted-foreground">
          {complexNote ? complexNote : `Preview for ${component.name}`}
        </p>
        <span className="text-xs text-muted-foreground/70">
          Import from @/components/ui/{component.file}
        </span>
      </div>
    </div>
  )
}
