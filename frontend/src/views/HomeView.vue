<script setup lang="ts">
import { onUnmounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import { useHead } from '@/composables/useHead'
import BaseButton from '@/components/ui/BaseButton.vue'
import RateWidget from '@/components/home/RateWidget.vue'
import ProductTeaserSection from '@/components/home/ProductTeaserSection.vue'
import PartnerBanksStrip from '@/components/home/PartnerBanksStrip.vue'

const { t } = useI18n()

const clearHead = useHead({
  title: t('home.seo.title'),
  description: t('home.seo.description'),
  jsonLd: [
    {
      '@context': 'https://schema.org',
      '@type': 'Organization',
      name: 'Sravni.tj',
      url: 'https://sravni.tj',
    },
    {
      '@context': 'https://schema.org',
      '@type': 'WebSite',
      name: 'Sravni.tj',
      url: 'https://sravni.tj',
    },
  ],
})
onUnmounted(clearHead)
</script>

<template>
  <div class="home">
    <section class="hero">
      <div class="container hero__inner">
        <h1 class="hero__title">
          {{ t('home.hero.titleLine1') }}<br />
          {{ t('home.hero.titleLine2') }}
          <span class="hero__accent">{{ t('home.hero.titleLine3') }}</span>
        </h1>
        <p class="hero__sub">{{ t('home.hero.subtitle') }}</p>
        <div class="hero__ctas">
          <RouterLink to="/credit"><BaseButton variant="primary" size="lg">{{ t('home.hero.ctaCredit') }}</BaseButton></RouterLink>
          <RouterLink to="/deposit"><BaseButton variant="secondary" size="lg">{{ t('home.hero.ctaDeposit') }}</BaseButton></RouterLink>
        </div>
      </div>
    </section>

    <RateWidget />

    <ProductTeaserSection
      category="credit"
      :title="t('home.credits.title')"
      :cta-label="t('home.credits.cta')"
      cta-to="/credit"
    />

    <ProductTeaserSection
      category="deposit"
      :title="t('home.deposits.title')"
      :cta-label="t('home.deposits.cta')"
      cta-to="/deposit"
      tinted
    />

    <PartnerBanksStrip />
  </div>
</template>

<style scoped>
.hero {
  padding: var(--space-16) 0 var(--space-12);
  position: relative;
  overflow: hidden;
}
.hero::before {
  content: '';
  position: absolute;
  top: -120px;
  right: -160px;
  width: 480px;
  height: 480px;
  background: radial-gradient(circle, var(--color-primary-soft-strong) 0%, transparent 70%);
  z-index: 0;
}
.hero__inner {
  position: relative;
  z-index: 1;
  max-width: 60ch;
}
.hero__title {
  font-family: var(--font-display);
  font-size: clamp(2.25rem, 4vw, var(--fs-4xl));
  font-weight: 800;
  letter-spacing: -0.03em;
  line-height: 1.06;
  margin: 0 0 var(--space-5);
}
.hero__accent {
  color: var(--color-primary);
}
.hero__sub {
  font-size: var(--fs-lg);
  color: var(--color-text-secondary);
  max-width: 46ch;
  margin: 0 0 var(--space-8);
}
.hero__ctas {
  display: flex;
  gap: var(--space-4);
  flex-wrap: wrap;
}

@media (max-width: 520px) {
  .hero {
    padding: var(--space-10) 0 var(--space-8);
  }
  .hero__ctas {
    flex-direction: column;
    align-items: stretch;
  }
}
</style>
