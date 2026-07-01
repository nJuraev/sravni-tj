<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { adminApi } from '@/api/admin'
import { ApiError } from '@/api/errors'
import type { AdminLead } from '@/types/admin'
import BaseButton from '@/components/ui/BaseButton.vue'

const leads = ref<AdminLead[]>([])
const loading = ref(true)
const search = ref('')
const page = ref(1)
const lastPage = ref(1)
const total = ref(0)

async function load() {
  loading.value = true
  try {
    const res = await adminApi.listLeads({ search: search.value, page: page.value, per_page: 25 })
    leads.value = res.data
    lastPage.value = res.meta.last_page
    total.value = res.meta.total
    page.value = res.meta.current_page
  } finally {
    loading.value = false
  }
}
onMounted(load)

function applySearch() {
  page.value = 1
  load()
}
function go(delta: number) {
  const next = page.value + delta
  if (next < 1 || next > lastPage.value) return
  page.value = next
  load()
}

async function remove(lead: AdminLead) {
  if (!confirm(`Удалить заявку от «${lead.full_name}»?`)) return
  try {
    await adminApi.deleteLead(lead.id)
    await load()
  } catch (e) {
    alert(e instanceof ApiError ? e.message : 'Не удалось удалить.')
  }
}

function fmtDate(iso: string | null): string {
  if (!iso) return '—'
  const d = new Date(iso)
  return d.toLocaleString('ru-RU', { dateStyle: 'short', timeStyle: 'short' })
}
</script>

<template>
  <div>
    <div class="adm__head">
      <h1 class="adm__title">Заявки <span style="color: var(--color-text-muted); font-size: var(--fs-md)">({{ total }})</span></h1>
      <div class="adm__toolbar">
        <input
          v-model="search"
          class="adm-input"
          type="search"
          placeholder="Имя или телефон…"
          style="width: 220px"
          @keyup.enter="applySearch"
        />
        <BaseButton variant="secondary" @click="applySearch">Найти</BaseButton>
      </div>
    </div>

    <div class="adm-card">
      <div v-if="loading" class="adm-empty">Загрузка…</div>
      <div v-else-if="!leads.length" class="adm-empty">Заявок нет</div>
      <table v-else class="adm-table">
        <thead>
          <tr>
            <th>Дата</th>
            <th>Имя</th>
            <th>Телефон</th>
            <th>Продукт</th>
            <th>Банк</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="lead in leads" :key="lead.id">
            <td>{{ fmtDate(lead.created_at) }}</td>
            <td><strong>{{ lead.full_name }}</strong></td>
            <td><a :href="`tel:${lead.phone}`">{{ lead.phone }}</a></td>
            <td>{{ lead.product?.name_ru ?? '—' }}</td>
            <td>{{ lead.bank?.name_ru ?? '—' }}</td>
            <td>
              <div class="adm-table__actions">
                <BaseButton size="sm" variant="danger" @click="remove(lead)">Удалить</BaseButton>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div v-if="lastPage > 1" class="adm-pagination">
      <BaseButton size="sm" variant="secondary" :disabled="page <= 1" @click="go(-1)">←</BaseButton>
      <span>{{ page }} / {{ lastPage }}</span>
      <BaseButton size="sm" variant="secondary" :disabled="page >= lastPage" @click="go(1)">→</BaseButton>
    </div>
  </div>
</template>
