<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import RouterLink from '@/components/nav/LocaleLink.vue'
import StateMessage from '@/components/ui/StateMessage.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import { useHttpStatus } from '@/composables/useHttpStatus'
import { useSeo } from '@/composables/useSeo'

const { t } = useI18n()
useHttpStatus(404)
// No locale twin to advertise for an unmatched path — noindex, no hreflang.
useSeo({ title: t('notFound.title'), description: t('notFound.seoDescription'), noindex: true, hreflang: false })
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
