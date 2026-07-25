<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink, useRoute, type RouteLocationRaw } from 'vue-router'
import { LOCALE_PREFIX, getLocaleFromRoute } from '@/router/locale'

defineOptions({ inheritAttrs: false })

const props = defineProps<{ to: RouteLocationRaw }>()
const route = useRoute()

/**
 * Prefixes a relative string `to` with /tj on Tajik pages, so every
 * `<RouterLink to="/credit">` across the app stays locale-correct with zero
 * template changes (this component replaces vue-router's RouterLink import
 * under the same local name). Named/object `to` values pass through untouched.
 */
const localizedTo = computed<RouteLocationRaw>(() => {
  if (typeof props.to !== 'string') return props.to
  if (getLocaleFromRoute(route) !== 'tj') return props.to
  if (props.to === LOCALE_PREFIX || props.to.startsWith(`${LOCALE_PREFIX}/`)) return props.to
  return props.to === '/' ? LOCALE_PREFIX : `${LOCALE_PREFIX}${props.to}`
})
</script>

<template>
  <RouterLink :to="localizedTo" v-bind="$attrs">
    <slot />
  </RouterLink>
</template>
