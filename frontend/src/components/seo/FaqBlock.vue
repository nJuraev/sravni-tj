<script setup lang="ts">
import { computed } from 'vue'
import { useHead } from '@unhead/vue'

export interface FaqItem {
  question: string
  answer: string
}

const props = defineProps<{
  title: string
  items: FaqItem[]
}>()

// FAQPage JSON-LD — self-contained so any page can drop this in without
// wiring its own schema; merges with the page's other useSeo() JSON-LD.
useHead({
  script: computed(() =>
    props.items.length
      ? [
          {
            type: 'application/ld+json',
            innerHTML: JSON.stringify({
              '@context': 'https://schema.org',
              '@type': 'FAQPage',
              mainEntity: props.items.map((item) => ({
                '@type': 'Question',
                name: item.question,
                acceptedAnswer: { '@type': 'Answer', text: item.answer },
              })),
            }),
          },
        ]
      : undefined,
  ),
})
</script>

<template>
  <section v-if="items.length" class="faq">
    <h2 class="faq__title">{{ title }}</h2>
    <div class="faq__list">
      <details v-for="(item, i) in items" :key="i" class="faq__item">
        <summary class="faq__question">{{ item.question }}</summary>
        <p class="faq__answer">{{ item.answer }}</p>
      </details>
    </div>
  </section>
</template>

<style scoped>
.faq {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
}
.faq__title {
  font-size: var(--fs-lg);
}
.faq__list {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
}
.faq__item {
  padding: var(--space-4);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  background: var(--color-bg);
}
.faq__question {
  cursor: pointer;
  font-weight: 600;
}
.faq__answer {
  margin: var(--space-3) 0 0;
  color: var(--color-text-secondary);
}
</style>
