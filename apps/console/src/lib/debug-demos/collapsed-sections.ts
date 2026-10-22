import { createDemoCategoryStore } from '@/lib/debug-demos/category-store'

const store = createDemoCategoryStore('debug:demoCollapsedCategories')

export const readCollapsedDemoCategories = store.read
export const writeCollapsedDemoCategories = store.write
