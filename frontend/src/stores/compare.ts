import { defineStore } from 'pinia'
import type { Product } from '@/types/api'

const STORAGE_KEY = 'sravni.compare'
export const COMPARE_LIMIT = 4
export const COMPARE_MIN = 2

interface CompareState {
  /** Cached short product data, keyed by id, surviving navigation. */
  items: Product[]
}

function loadPersisted(): Product[] {
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    if (!raw) return []
    const parsed = JSON.parse(raw)
    return Array.isArray(parsed) ? (parsed as Product[]) : []
  } catch {
    return []
  }
}

export const useCompareStore = defineStore('compare', {
  state: (): CompareState => ({
    items: loadPersisted(),
  }),
  getters: {
    ids: (state): number[] => state.items.map((p) => p.id),
    count: (state): number => state.items.length,
    isFull: (state): boolean => state.items.length >= COMPARE_LIMIT,
    hasEnough: (state): boolean => state.items.length >= COMPARE_MIN,
    has:
      (state) =>
      (id: number): boolean =>
        state.items.some((p) => p.id === id),
  },
  actions: {
    /** Add a product. Returns false if the limit (4) is reached. */
    add(product: Product): boolean {
      if (this.items.some((p) => p.id === product.id)) return true
      if (this.items.length >= COMPARE_LIMIT) return false
      this.items.push(product)
      this.persist()
      return true
    },
    remove(id: number): void {
      this.items = this.items.filter((p) => p.id !== id)
      this.persist()
    },
    /** Toggle membership; returns false only when a blocked add hit the limit. */
    toggle(product: Product): boolean {
      if (this.items.some((p) => p.id === product.id)) {
        this.remove(product.id)
        return true
      }
      return this.add(product)
    },
    clear(): void {
      this.items = []
      this.persist()
    },
    persist(): void {
      try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(this.items))
      } catch {
        /* ignore quota / privacy-mode failures */
      }
    },
  },
})
