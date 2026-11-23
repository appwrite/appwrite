import { createDemoCategoryStore } from '@/lib/debug-demos/category-store'

const store = createDemoCategoryStore('debug:demoHiddenCategories')

export const readHiddenDemoCategories = store.read
export const writeHiddenDemoCategories = store.write
