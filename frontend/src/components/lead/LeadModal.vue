<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useLeadModalStore } from '@/stores/leadModal'
import BaseModal from '@/components/ui/BaseModal.vue'
import LeadForm from './LeadForm.vue'

const { t } = useI18n()
const leadModal = useLeadModalStore()
</script>

<template>
  <BaseModal :open="leadModal.isOpen" :title="t('lead.title')" @close="leadModal.close()">
    <LeadForm v-if="leadModal.product" :product="leadModal.product" @done="leadModal.close()" />
  </BaseModal>
</template>
