<template>
  <!-- Read-only view of a meeting summary, Markdown rendered and sanitized -->
  <!-- The HTML is sanitized by DOMPurify, see below -->
  <!-- eslint-disable vue/no-v-html -->
  <div
    class="room-description link-color px-2 break-words"
    data-test="room-meeting-summary-viewer"
    v-html="html"
  />
  <!-- eslint-enable vue/no-v-html -->
</template>
<script setup>
import { computed } from "vue";
import { marked } from "marked";
import createDOMPurify from "dompurify";

const props = defineProps({
  markdown: {
    type: String,
    required: true,
  },
});

// Tags a meeting summary may use; no images (no requests to other hosts), no forms, no styles
const allowedTags = [
  "h1",
  "h2",
  "h3",
  "h4",
  "h5",
  "h6",
  "p",
  "br",
  "hr",
  "a",
  "ul",
  "ol",
  "li",
  "strong",
  "em",
  "del",
  "s",
  "code",
  "pre",
  "blockquote",
  "table",
  "thead",
  "tbody",
  "tr",
  "th",
  "td",
];
const allowedAttributes = ["href", "title"];

// Own DOMPurify instance, so the hook does not affect other components
const domPurify = createDOMPurify();

// Links open in a new tab without access to this window
domPurify.addHook("afterSanitizeAttributes", function (node) {
  if (node.tagName === "A" && node.hasAttribute("href")) {
    node.setAttribute("target", "_blank");
    node.setAttribute("rel", "noopener noreferrer");
  }
});

const html = computed(() =>
  domPurify.sanitize(
    marked.parse(props.markdown, { async: false, gfm: true }),
    {
      ALLOWED_TAGS: allowedTags,
      ALLOWED_ATTR: allowedAttributes,
      ALLOW_DATA_ATTR: false,
      ALLOW_ARIA_ATTR: false,
    },
  ),
);
</script>
