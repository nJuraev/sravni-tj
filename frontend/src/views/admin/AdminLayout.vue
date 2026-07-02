<script setup lang="ts">
import { computed, h, ref } from 'vue'
import { useRoute, useRouter, RouterView } from 'vue-router'
import {
  NConfigProvider,
  NMessageProvider,
  NDialogProvider,
  NLayout,
  NLayoutSider,
  NLayoutHeader,
  NLayoutContent,
  NMenu,
  NIcon,
  NButton,
  NDropdown,
  type MenuOption,
} from 'naive-ui'
import {
  BusinessOutline,
  DocumentTextOutline,
  PeopleOutline,
  LogOutOutline,
  PersonCircleOutline,
} from '@vicons/ionicons5'
import { useAdminStore } from '@/stores/admin'
import { adminThemeOverrides } from './theme'

const admin = useAdminStore()
const route = useRoute()
const router = useRouter()

const collapsed = ref(false)

function renderIcon(icon: unknown) {
  return () => h(NIcon, null, { default: () => h(icon as never) })
}

const menuOptions = computed<MenuOption[]>(() => {
  const items: MenuOption[] = [
    { label: 'Банки', key: 'admin-banks', icon: renderIcon(BusinessOutline) },
    { label: 'Заявки', key: 'admin-leads', icon: renderIcon(DocumentTextOutline) },
  ]
  if (admin.isAdmin) {
    items.push({ label: 'Пользователи', key: 'admin-users', icon: renderIcon(PeopleOutline) })
  }
  return items
})

// Активный пункт меню по имени текущего роута (детальная банка подсвечивает «Банки»).
const activeKey = computed(() => {
  const name = route.name as string | undefined
  if (name === 'admin-bank') return 'admin-banks'
  return name ?? 'admin-banks'
})

function onMenuSelect(key: string) {
  if (key !== route.name) router.push({ name: key })
}

const userMenuOptions = [
  { label: 'Выйти', key: 'logout', icon: renderIcon(LogOutOutline) },
]

async function onUserAction(key: string) {
  if (key === 'logout') {
    await admin.logout()
    router.push({ name: 'admin-login' })
  }
}
</script>

<template>
  <n-config-provider :theme-overrides="adminThemeOverrides">
    <n-message-provider>
      <n-dialog-provider>
        <n-layout style="height: 100vh">
          <n-layout-header bordered class="adm-header">
            <div class="adm-brand">Sravni · Админка</div>
            <div class="adm-header__spacer" />
            <n-dropdown :options="userMenuOptions" trigger="click" @select="onUserAction">
              <n-button quaternary>
                <template #icon>
                  <n-icon><PersonCircleOutline /></n-icon>
                </template>
                {{ admin.user?.name }}
                <span class="adm-header__role">
                  · {{ admin.user?.role === 'admin' ? 'администратор' : 'редактор' }}
                </span>
              </n-button>
            </n-dropdown>
          </n-layout-header>

          <n-layout has-sider style="height: calc(100vh - 56px)">
            <n-layout-sider
              bordered
              :collapsed="collapsed"
              collapse-mode="width"
              :collapsed-width="64"
              :width="240"
              show-trigger
              @collapse="collapsed = true"
              @expand="collapsed = false"
            >
              <n-menu
                :value="activeKey"
                :options="menuOptions"
                :collapsed="collapsed"
                :collapsed-width="64"
                @update:value="onMenuSelect"
              />
            </n-layout-sider>

            <n-layout-content class="adm-content" content-style="padding: 24px;">
              <RouterView />
            </n-layout-content>
          </n-layout>
        </n-layout>
      </n-dialog-provider>
    </n-message-provider>
  </n-config-provider>
</template>

<style scoped>
.adm-brand {
  height: 56px;
  display: flex;
  align-items: center;
  padding: 0 20px;
  font-family: var(--font-display, inherit);
  font-weight: 700;
  font-size: 16px;
  color: #0050c8;
  white-space: nowrap;
  overflow: hidden;
}
.adm-header {
  height: 56px;
  display: flex;
  align-items: center;
  padding: 0 20px;
}
.adm-header__spacer {
  flex: 1;
}
.adm-header__role {
  color: #999;
  margin-left: 2px;
}
.adm-content {
  background: #f6f7f9;
}
</style>
