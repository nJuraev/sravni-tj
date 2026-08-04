import { useI18n } from 'vue-i18n'
import { api } from '@/api/client'
import type {
  BankReviewRequest,
  BestRateQuery,
  LeadRequest,
  Locale,
  ProductQuery,
  RateListQuery,
} from '@/types/api'

/**
 * Locale-bound API client. Binds every call to the component's own i18n
 * instance instead of a shared module-level locale — safe under concurrent
 * SSR requests, where one Node process renders many requests at once.
 */
export function useApi() {
  const { locale } = useI18n()
  const l = () => locale.value as Locale

  return {
    getProducts: (query?: ProductQuery) => api.getProducts(l(), query),
    getProduct: (id: number) => api.getProduct(l(), id),
    getBanks: () => api.getBanks(l()),
    getBank: (id: number) => api.getBank(l(), id),
    getBestRate: (query: BestRateQuery) => api.getBestRate(l(), query),
    createLead: (body: LeadRequest) => api.createLead(l(), body),
    getRates: (query?: RateListQuery) => api.getRates(l(), query),
    getBankReviews: (bankId: number, page?: number) => api.getBankReviews(l(), bankId, page),
    createBankReview: (bankId: number, body: BankReviewRequest) => api.createBankReview(l(), bankId, body),
    initTelegramSubscribe: () => api.initTelegramSubscribe(l()),
  }
}
