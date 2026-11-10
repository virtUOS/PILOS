<template>
  <!--
    Read-only view of a meeting summary (Markdown).
    The text is shown as escaped plain text; no HTML from the summary is ever inserted.
  -->
  <div
    class="room-meeting-summary px-2 break-words whitespace-pre-wrap"
    data-test="room-meeting-summary-viewer"
  >
    {{ props.markdown }}
  </div>
</template>
<script setup>
const props = defineProps({
  markdown: {
    type: String,
    required: true,
  },
});
</script>
