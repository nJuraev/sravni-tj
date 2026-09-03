<script setup lang="ts">
import { onBeforeUnmount, watch } from 'vue'
import { useEditor, EditorContent } from '@tiptap/vue-3'
import StarterKit from '@tiptap/starter-kit'
import Image from '@tiptap/extension-image'
import Link from '@tiptap/extension-link'
import { NButton, NButtonGroup, NIcon, useMessage } from 'naive-ui'
import {
  ImageOutline, LinkOutline, ListOutline, ArrowUndoOutline, ArrowRedoOutline,
} from '@vicons/ionicons5'
import { adminApi } from '@/api/admin'
import { ApiError } from '@/api/errors'

const props = defineProps<{ modelValue: string }>()
const emit = defineEmits<{ 'update:modelValue': [value: string] }>()

const message = useMessage()

const editor = useEditor({
  content: props.modelValue,
  extensions: [
    StarterKit,
    Image,
    Link.configure({ openOnClick: false, autolink: true }),
  ],
  editorProps: {
    attributes: { class: 'aeditor__content' },
  },
  onUpdate: ({ editor: e }) => emit('update:modelValue', e.getHTML()),
})

// Внешние сбросы формы (переоткрытие на другую статью) должны перезагружать контент.
watch(
  () => props.modelValue,
  (value) => {
    if (editor.value && value !== editor.value.getHTML()) {
      editor.value.commands.setContent(value, false)
    }
  },
)

onBeforeUnmount(() => editor.value?.destroy())

function setLink(): void {
  if (!editor.value) return
  const previous = editor.value.getAttributes('link').href as string | undefined
  const url = window.prompt('Ссылка (URL):', previous ?? 'https://')
  if (url === null) return
  if (url === '') {
    editor.value.chain().focus().extendMarkRange('link').unsetLink().run()
    return
  }
  editor.value.chain().focus().extendMarkRange('link').setLink({ href: url }).run()
}

async function insertImage(): Promise<void> {
  const input = document.createElement('input')
  input.type = 'file'
  input.accept = 'image/png,image/jpeg,image/webp'
  input.onchange = async () => {
    const file = input.files?.[0]
    if (!file || !editor.value) return
    try {
      const res = await adminApi.uploadArticleImage(file)
      editor.value.chain().focus().setImage({ src: res.data.url }).run()
    } catch (e) {
      message.error(e instanceof ApiError ? e.message : 'Не удалось загрузить картинку')
    }
  }
  input.click()
}
</script>

<template>
  <div class="aeditor">
    <div v-if="editor" class="aeditor__toolbar">
      <n-button-group size="small">
        <n-button :type="editor.isActive('bold') ? 'primary' : 'default'" @click="editor.chain().focus().toggleBold().run()">
          <strong>B</strong>
        </n-button>
        <n-button :type="editor.isActive('italic') ? 'primary' : 'default'" @click="editor.chain().focus().toggleItalic().run()">
          <em>I</em>
        </n-button>
        <n-button :type="editor.isActive('heading', { level: 2 }) ? 'primary' : 'default'" @click="editor.chain().focus().toggleHeading({ level: 2 }).run()">
          H2
        </n-button>
        <n-button :type="editor.isActive('heading', { level: 3 }) ? 'primary' : 'default'" @click="editor.chain().focus().toggleHeading({ level: 3 }).run()">
          H3
        </n-button>
        <n-button :type="editor.isActive('bulletList') ? 'primary' : 'default'" @click="editor.chain().focus().toggleBulletList().run()">
          <n-icon><ListOutline /></n-icon>
        </n-button>
        <n-button :type="editor.isActive('blockquote') ? 'primary' : 'default'" @click="editor.chain().focus().toggleBlockquote().run()">
          “”
        </n-button>
        <n-button :type="editor.isActive('link') ? 'primary' : 'default'" @click="setLink">
          <n-icon><LinkOutline /></n-icon>
        </n-button>
        <n-button @click="insertImage">
          <n-icon><ImageOutline /></n-icon>
        </n-button>
        <n-button @click="editor.chain().focus().undo().run()">
          <n-icon><ArrowUndoOutline /></n-icon>
        </n-button>
        <n-button @click="editor.chain().focus().redo().run()">
          <n-icon><ArrowRedoOutline /></n-icon>
        </n-button>
      </n-button-group>
    </div>
    <editor-content :editor="editor" class="aeditor__body" />
  </div>
</template>

<style scoped>
.aeditor {
  border: 1px solid #d9d9d9;
  border-radius: 3px;
  overflow: hidden;
}
.aeditor__toolbar {
  padding: 8px;
  background: #fafafa;
  border-bottom: 1px solid #d9d9d9;
}
.aeditor__body {
  padding: 12px 16px;
  min-height: 260px;
  max-height: 560px;
  overflow-y: auto;
}
.aeditor__body :deep(.aeditor__content) {
  outline: none;
}
.aeditor__body :deep(p) {
  margin: 0 0 0.8em;
}
.aeditor__body :deep(img) {
  max-width: 100%;
  border-radius: 4px;
}
.aeditor__body :deep(blockquote) {
  margin: 0 0 0.8em;
  padding-left: 12px;
  border-left: 3px solid #d9d9d9;
  color: #666;
}
</style>
