<template>
  <Button
    v-tooltip="$t('rooms.meeting_summaries.view')"
    :aria-label="
      $t('rooms.meeting_summaries.view_aria', {
        date: $d(new Date(props.start), 'datetimeShort'),
      })
    "
    icon="fa-solid fa-eye"
    :disabled="props.disabled"
    data-test="room-meeting-summaries-view-button"
    @click="showModal"
  />

  <Dialog
    v-model:visible="modalVisible"
    modal
    :header="
      $t('rooms.meeting_summaries.view_title', {
        date: $d(new Date(props.start), 'datetimeShort'),
      })
    "
    :style="{ width: '900px' }"
    :breakpoints="{ '975px': '90vw' }"
    :draggable="false"
    data-test="room-meeting-summaries-view-dialog"
  >
    <OverlayComponent :show="isLoading || loadingError" :z-index="1">
      <template #overlay>
        <LoadingRetryButton :error="loadingError" @reload="loadContent" />
      </template>
      <RoomMeetingSummaryViewer :markdown="content" />
    </OverlayComponent>

    <template #footer>
      <div class="flex justify-end gap-2">
        <Button
          :label="$t('app.close')"
          severity="secondary"
          icon="fa-solid fa-times"
          data-test="dialog-close-button"
          @click="modalVisible = false"
        />
      </div>
    </template>
  </Dialog>
</template>
<script setup>
import { ref } from "vue";
import { useI18n } from "vue-i18n";
import { useApi } from "../composables/useApi.js";
import { useToast } from "../composables/useToast.js";
import { MEETING_SUMMARY } from "../constants/modelNames.js";
import { HTTP_STATUS_NOT_FOUND } from "../constants/httpStatusCodes.js";
import RoomMeetingSummaryViewer from "./RoomMeetingSummaryViewer.vue";

const props = defineProps({
  roomId: {
    type: String,
    required: true,
  },
  summaryId: {
    type: Number,
    required: true,
  },
  start: {
    type: String,
    required: true,
  },
  roomAuthToken: {
    type: Object,
    default: null,
  },
  disabled: {
    type: Boolean,
    default: false,
  },
});

const emit = defineEmits(["notFound"]);

const api = useApi();
const toast = useToast();
const { t } = useI18n();

const modalVisible = ref(false);
const isLoading = ref(false);
const loadingError = ref(false);
const content = ref("");

function showModal() {
  content.value = "";
  modalVisible.value = true;
  loadContent();
}

/**
 * Load the summary text, it is not part of the list
 */
function loadContent() {
  isLoading.value = true;
  loadingError.value = false;

  const config = { params: {} };
  if (props.roomAuthToken) {
    config.params.room_auth_token = props.roomAuthToken.id;
    config.params.room_auth_token_type = props.roomAuthToken.type;
  }

  api
    .call(
      "rooms/" + props.roomId + "/meetingSummaries/" + props.summaryId,
      config,
    )
    .then((response) => {
      content.value = response.data.data.content ?? "";
    })
    .catch((error) => {
      if (
        error.response?.status === HTTP_STATUS_NOT_FOUND &&
        error.response.data?.model === MEETING_SUMMARY
      ) {
        toast.error(t("rooms.meeting_summaries.gone"));
        modalVisible.value = false;
        emit("notFound");
        return;
      }
      loadingError.value = true;
      api.error(error, { redirectOnUnauthenticated: false });
    })
    .finally(() => {
      isLoading.value = false;
    });
}
</script>
