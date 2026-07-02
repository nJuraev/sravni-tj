<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import StateMessage from '@/components/ui/StateMessage.vue'
import BaseButton from '@/components/ui/BaseButton.vue'

const { t } = useI18n()
</script>

<template>
  <div class="notfound container">
    <StateMessage :title="t('notFound.title')" :hint="t('notFound.hint')">
      <template #action>
        <RouterLink to="/">
          <BaseButton variant="primary">{{ t('notFound.home') }}</BaseButton>
        </RouterLink>
      </template>
    </StateMessage>
  </div>
</template>

<style scoped>
.notfound {
  padding-block: var(--space-8);
}
</style>
