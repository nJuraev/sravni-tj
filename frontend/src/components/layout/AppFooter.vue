<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { USE_MOCKS } from '@/api/client'

const { t } = useI18n()
const year = new Date().getFullYear()
</script>

<template>
  <footer class="footer">
    <div class="container footer__inner">
      <div>
        <p class="footer__brand">{{ t('app.name') }}</p>
        <p class="footer__line">{{ t('app.tagline') }}</p>
      </div>
      <div class="footer__meta">
        <p class="footer__line">© {{ year }} · {{ t('footer.rights') }}</p>
        <p v-if="USE_MOCKS" class="footer__line footer__line--mock">{{ t('footer.mockNotice') }}</p>
      </div>
    </div>
  </footer>
</template>

<style scoped>
.footer {
  margin-top: var(--space-16);
  background: var(--color-footer-bg);
  color: var(--color-footer-text);
}
.footer__inner {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  gap: var(--space-6);
  padding-block: var(--space-8);
}
.footer__brand {
  font-family: var(--font-display);
  font-weight: 800;
  font-size: var(--fs-lg);
  color: #fff;
}
.footer__line {
  font-size: var(--fs-sm);
  color: var(--color-footer-text);
}
.footer__line--mock {
  color: var(--color-text-muted);
  margin-top: var(--space-1);
}
.footer__meta {
  text-align: right;
}
@media (max-width: 640px) {
  .footer__meta {
    text-align: left;
  }
}
</style>
