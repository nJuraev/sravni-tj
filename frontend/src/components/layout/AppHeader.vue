<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import { useCompareStore } from '@/stores/compare'
import LanguageSwitcher from './LanguageSwitcher.vue'

const { t } = useI18n()
const compare = useCompareStore()
const menuOpen = ref(false)

function closeMenu() {
  menuOpen.value = false
}
</script>

<template>
  <header class="header">
    <div class="container header__inner">
      <RouterLink to="/credit" class="brand" @click="closeMenu">
        <span class="brand__mark" aria-hidden="true">
          <svg viewBox="0 0 32 32">
            <rect width="32" height="32" rx="7" fill="var(--color-primary)" />
            <path d="M9 20.5 14 11 18 18 23 11.5" stroke="#fff" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" fill="none" />
            <circle cx="23" cy="11.5" r="2" fill="var(--color-accent-green)" />
          </svg>
        </span>
        <span class="brand__name">{{ t('app.name') }}</span>
      </RouterLink>

      <button
        type="button"
        class="header__burger"
        :aria-expanded="menuOpen"
        :aria-label="t('common.more')"
        @click="menuOpen = !menuOpen"
      >
        <span /><span /><span />
      </button>

      <nav class="nav" :class="{ 'nav--open': menuOpen }" :aria-label="t('app.name')">
        <RouterLink to="/credit" class="nav__link" @click="closeMenu">{{ t('nav.credit') }}</RouterLink>
        <RouterLink to="/deposit" class="nav__link" @click="closeMenu">{{ t('nav.deposit') }}</RouterLink>
        <RouterLink to="/installment" class="nav__link" @click="closeMenu">{{ t('nav.installment') }}</RouterLink>
        <RouterLink to="/compare" class="nav__link nav__link--compare" @click="closeMenu">
          {{ t('nav.compare') }}
          <span v-if="compare.count" class="nav__counter">{{ compare.count }}</span>
        </RouterLink>
        <div class="nav__lang">
          <LanguageSwitcher />
        </div>
      </nav>
    </div>
  </header>
</template>

<style scoped>
.header {
  position: sticky;
  top: 0;
  z-index: 50;
  background: var(--color-bg);
  border-bottom: 1px solid var(--color-border);
}
.header__inner {
  display: flex;
  align-items: center;
  justify-content: space-between;
  height: var(--header-height);
  gap: var(--space-4);
}
.brand {
  display: inline-flex;
  align-items: center;
  gap: var(--space-2);
  color: var(--color-text-primary);
}
.brand:hover {
  color: var(--color-text-primary);
}
.brand__mark svg {
  width: 30px;
  height: 30px;
}
.brand__name {
  font-family: var(--font-display);
  font-weight: 800;
  font-size: var(--fs-lg);
  letter-spacing: -0.02em;
}
.nav {
  display: flex;
  align-items: center;
  gap: var(--space-2);
}
.nav__link {
  position: relative;
  display: inline-flex;
  align-items: center;
  gap: var(--space-2);
  padding: var(--space-2) var(--space-3);
  border-radius: var(--radius-md);
  font-family: var(--font-display);
  font-weight: 600;
  font-size: var(--fs-base);
  color: var(--color-text-primary);
}
.nav__link:hover {
  color: var(--color-primary);
  background: var(--color-primary-soft);
}
.nav__link.router-link-active {
  color: var(--color-primary);
}
.nav__counter {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 20px;
  height: 20px;
  padding: 0 6px;
  border-radius: var(--radius-pill);
  background: var(--color-primary);
  color: #fff;
  font-size: var(--fs-xs);
  font-weight: 700;
}
.nav__lang {
  margin-left: var(--space-2);
}
.header__burger {
  display: none;
  flex-direction: column;
  justify-content: center;
  gap: 5px;
  width: 42px;
  height: 42px;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  background: var(--color-bg);
  cursor: pointer;
}
.header__burger span {
  display: block;
  width: 18px;
  height: 2px;
  margin-inline: auto;
  background: var(--color-text-primary);
  border-radius: 2px;
}

@media (max-width: 768px) {
  .header__burger {
    display: flex;
  }
  .nav {
    position: absolute;
    inset: var(--header-height) 0 auto 0;
    flex-direction: column;
    align-items: stretch;
    padding: var(--space-3);
    gap: var(--space-1);
    background: var(--color-bg);
    border-bottom: 1px solid var(--color-border);
    box-shadow: var(--shadow-md);
    transform: translateY(-8px);
    opacity: 0;
    pointer-events: none;
    transition:
      transform var(--transition),
      opacity var(--transition);
  }
  .nav--open {
    transform: translateY(0);
    opacity: 1;
    pointer-events: auto;
  }
  .nav__lang {
    margin-left: 0;
    padding: var(--space-2) var(--space-3);
  }
}
</style>
