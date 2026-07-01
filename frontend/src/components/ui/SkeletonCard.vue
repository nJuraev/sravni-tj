<script setup lang="ts"></script>

<template>
  <div class="skeleton" aria-busy="true" aria-label="loading">
    <div class="skeleton__row skeleton__row--head">
      <div class="skeleton__block skeleton__block--avatar" />
      <div class="skeleton__lines">
        <div class="skeleton__block skeleton__block--w60" />
        <div class="skeleton__block skeleton__block--w40" />
      </div>
    </div>
    <div class="skeleton__block skeleton__block--big" />
    <div class="skeleton__row">
      <div class="skeleton__block skeleton__block--pill" />
      <div class="skeleton__block skeleton__block--pill" />
    </div>
  </div>
</template>

<style scoped>
.skeleton {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
  padding: var(--space-5);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  background: var(--color-bg);
}
.skeleton__row {
  display: flex;
  gap: var(--space-3);
}
.skeleton__row--head {
  align-items: center;
}
.skeleton__lines {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
  flex: 1;
}
.skeleton__block {
  background: linear-gradient(
    90deg,
    var(--color-bg-section) 25%,
    var(--color-bg-offwhite) 50%,
    var(--color-bg-section) 75%
  );
  background-size: 200% 100%;
  border-radius: var(--radius-sm);
  height: 14px;
  animation: shimmer 1.3s infinite;
}
.skeleton__block--avatar {
  width: 44px;
  height: 44px;
  border-radius: var(--radius-md);
  flex-shrink: 0;
}
.skeleton__block--w60 {
  width: 60%;
}
.skeleton__block--w40 {
  width: 40%;
}
.skeleton__block--big {
  height: 40px;
}
.skeleton__block--pill {
  width: 90px;
  height: 26px;
  border-radius: var(--radius-pill);
}
@keyframes shimmer {
  to {
    background-position: -200% 0;
  }
}
</style>
