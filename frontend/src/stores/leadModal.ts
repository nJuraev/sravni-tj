import { defineStore } from 'pinia'
import type { Product } from '@/types/api'

interface LeadModalState {
  isOpen: boolean
  product: Product | null
}

/** Controls the global lead (application) modal, openable from any page. */
export const useLeadModalStore = defineStore('leadModal', {
  state: (): LeadModalState => ({
    isOpen: false,
    product: null,
  }),
  actions: {
    open(product: Product): void {
      this.product = product
      this.isOpen = true
    },
    close(): void {
      this.isOpen = false
      this.product = null
    },
  },
})
