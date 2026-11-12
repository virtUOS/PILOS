<template>
  <Button
    v-tooltip="$t('rooms.meeting_summaries.download')"
    :aria-label="
      $t('rooms.meeting_summaries.download_aria', {
        date: $d(new Date(props.start), 'datetimeShort'),
      })
    "
    icon="fa-solid fa-download"
    :disabled="props.disabled || isLoading"
    :loading="isLoading"
    data-test="room-meeting-summaries-download-button"
    @click="download"
  />
</template>
<script setup>
import { ref } from "vue";
import { useI18n } from "vue-i18n";
import { useApi } from "../composables/useApi.js";
import { useToast } from "../composables/useToast.js";
import { MEETING_SUMMARY } from "../constants/modelNames.js";
import { HTTP_STATUS_NOT_FOUND } from "../constants/httpStatusCodes.js";

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

const isLoading = ref(false);

/**
 * Get the file name from the Content-Disposition header of the response
 */
function getFilename(response) {
  const match = /filename="?([^";]+)"?/.exec(
    response.headers["content-disposition"] ?? "",
  );
  return match ? match[1] : "meeting-summary.md";
}

/**
 * Load the summary as a file (with the room auth token, if any) and save it in the browser
 */
function download() {
  isLoading.value = true;

  const config = { params: {}, responseType: "blob" };
  if (props.roomAuthToken) {
    config.params.room_auth_token = props.roomAuthToken.id;
    config.params.room_auth_token_type = props.roomAuthToken.type;
  }

  api
    .call(
      "rooms/" +
        props.roomId +
        "/meetingSummaries/" +
        props.summaryId +
        "/download",
      config,
    )
    .then((response) => {
      const url = URL.createObjectURL(response.data);
      const link = document.createElement("a");
      link.href = url;
      link.download = getFilename(response);
      link.click();
      URL.revokeObjectURL(url);
    })
    .catch(async (error) => {
      // Error responses are also loaded as blob, read them as JSON for the error handling
      if (error.response?.data instanceof Blob) {
        try {
          error.response.data = JSON.parse(await error.response.data.text());
        } catch {
          error.response.data = {};
        }
      }

      if (
        error.response?.status === HTTP_STATUS_NOT_FOUND &&
        error.response.data?.model === MEETING_SUMMARY
      ) {
        toast.error(t("rooms.meeting_summaries.gone"));
        emit("notFound");
        return;
      }
      api.error(error, { redirectOnUnauthenticated: false });
    })
    .finally(() => {
      isLoading.value = false;
    });
}
</script>
