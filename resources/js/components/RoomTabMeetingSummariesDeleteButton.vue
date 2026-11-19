<template>
  <Button
    v-tooltip="$t('rooms.meeting_summaries.delete')"
    :aria-label="
      $t('rooms.meeting_summaries.delete_aria', {
        date: $d(new Date(props.start), 'datetimeShort'),
      })
    "
    :disabled="disabled"
    severity="danger"
    icon="fa-solid fa-trash"
    data-test="room-meeting-summaries-delete-button"
    @click="modalVisible = true"
  />

  <Dialog
    v-model:visible="modalVisible"
    modal
    :header="$t('rooms.meeting_summaries.modals.delete.title')"
    :style="{ width: '500px' }"
    :breakpoints="{ '575px': '90vw' }"
    :draggable="false"
    :close-on-escape="!isLoadingAction"
    :dismissable-mask="false"
    :closable="!isLoadingAction"
    data-test="room-meeting-summaries-delete-dialog"
  >
    <template #footer>
      <div class="flex justify-end gap-2">
        <Button
          :label="$t('app.no')"
          severity="secondary"
          :disabled="isLoadingAction"
          data-test="dialog-cancel-button"
          @click="modalVisible = false"
        />
        <Button
          :label="$t('app.yes')"
          severity="danger"
          :loading="isLoadingAction"
          data-test="dialog-continue-button"
          @click="deleteSummary"
        />
      </div>
    </template>

    <span style="overflow-wrap: break-word">
      {{ $t("rooms.meeting_summaries.modals.delete.confirm") }}
    </span>
  </Dialog>
</template>
<script setup>
import { ref } from "vue";
import { useI18n } from "vue-i18n";
import { useApi } from "../composables/useApi.js";
import { useToast } from "../composables/useToast.js";
import { MEETING_SUMMARY } from "../constants/modelNames.js";
import { HTTP_STATUS_NOT_FOUND } from "../constants/httpStatusCodes.js";

const props = defineProps({
  summaryId: {
    type: Number,
    required: true,
  },
  roomId: {
    type: String,
    required: true,
  },
  start: {
    type: String,
    required: true,
  },
  disabled: {
    type: Boolean,
    default: false,
  },
});

const emit = defineEmits(["deleted", "notFound"]);

const api = useApi();
const toast = useToast();
const { t } = useI18n();

const modalVisible = ref(false);
const isLoadingAction = ref(false);

function deleteSummary() {
  isLoadingAction.value = true;

  api
    .call("rooms/" + props.roomId + "/meetingSummaries/" + props.summaryId, {
      method: "delete",
    })
    .then(() => {
      modalVisible.value = false;
      emit("deleted");
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
      api.error(error, { redirectOnUnauthenticated: false });
    })
    .finally(() => {
      isLoadingAction.value = false;
    });
}
</script>
