<template>
  <Button
    v-tooltip="$t('rooms.meeting_summaries.edit')"
    :aria-label="
      $t('rooms.meeting_summaries.edit_aria', {
        date: $d(new Date(props.start), 'datetimeShort'),
      })
    "
    :disabled="disabled"
    severity="info"
    icon="fa-solid fa-edit"
    data-test="room-meeting-summaries-edit-button"
    @click="showModal"
  />

  <Dialog
    v-model:visible="modalVisible"
    modal
    :header="$t('rooms.meeting_summaries.modals.edit.title')"
    :style="{ width: '500px' }"
    :breakpoints="{ '575px': '90vw' }"
    :draggable="false"
    :close-on-escape="!isLoadingAction"
    :dismissable-mask="false"
    :closable="!isLoadingAction"
    data-test="room-meeting-summaries-edit-dialog"
  >
    <template #footer>
      <div class="flex justify-end gap-2">
        <Button
          :label="$t('app.cancel')"
          severity="secondary"
          :disabled="isLoadingAction"
          data-test="dialog-cancel-button"
          @click="modalVisible = false"
        />
        <Button
          :label="$t('app.save')"
          :loading="isLoadingAction"
          data-test="dialog-save-button"
          form="room-meeting-summaries-edit-form"
          type="submit"
        />
      </div>
    </template>

    <Form
      id="room-meeting-summaries-edit-form"
      :disabled="isLoadingAction"
      @submit="save"
    >
      <div class="field flex flex-col gap-2" data-test="access-field">
        <fieldset class="flex w-full flex-col gap-2">
          <label>{{ $t("rooms.recordings.access") }}</label>
          <div
            v-for="accessType in accessTypes"
            :key="accessType"
            class="flex items-center"
            :data-test="'access-' + accessType + '-field'"
          >
            <RadioButton
              v-model="newAccess"
              :disabled="isLoadingAction"
              pt:input:required
              :invalid="formErrors.fieldInvalid('access')"
              :input-id="'summary-access-' + accessType"
              name="access"
              :value="accessType"
            />
            <label :for="'summary-access-' + accessType" class="ml-2"
              ><RoomRecordingAccessBadge :access="accessType"
            /></label>
          </div>
          <FormError :errors="formErrors.fieldError('access')" />
        </fieldset>
      </div>
    </Form>
  </Dialog>
</template>
<script setup>
import { ref } from "vue";
import { useI18n } from "vue-i18n";
import { useApi } from "../composables/useApi.js";
import { useFormErrors } from "../composables/useFormErrors.js";
import { useToast } from "../composables/useToast.js";
import { MEETING_SUMMARY } from "../constants/modelNames.js";
import {
  HTTP_STATUS_NOT_FOUND,
  HTTP_STATUS_UNPROCESSABLE_ENTITY,
} from "../constants/httpStatusCodes.js";

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
  access: {
    type: Number,
    required: true,
  },
  disabled: {
    type: Boolean,
    default: false,
  },
});

const emit = defineEmits(["edited", "notFound"]);

const api = useApi();
const formErrors = useFormErrors();
const toast = useToast();
const { t } = useI18n();

const modalVisible = ref(false);
const newAccess = ref(null);
const isLoadingAction = ref(false);
const accessTypes = [0, 1, 2, 3];

function showModal() {
  newAccess.value = props.access;
  formErrors.clear();
  modalVisible.value = true;
}

function save() {
  isLoadingAction.value = true;
  formErrors.clear();

  api
    .call("rooms/" + props.roomId + "/meetingSummaries/" + props.summaryId, {
      method: "put",
      data: { access: newAccess.value },
    })
    .then(() => {
      modalVisible.value = false;
      emit("edited");
    })
    .catch((error) => {
      if (error.response) {
        if (
          error.response.status === HTTP_STATUS_NOT_FOUND &&
          error.response.data?.model === MEETING_SUMMARY
        ) {
          toast.error(t("rooms.meeting_summaries.gone"));
          modalVisible.value = false;
          emit("notFound");
          return;
        }
        if (error.response.status === HTTP_STATUS_UNPROCESSABLE_ENTITY) {
          formErrors.set(error.response.data.errors);
          return;
        }
      }
      api.error(error, { redirectOnUnauthenticated: false });
    })
    .finally(() => {
      isLoadingAction.value = false;
    });
}
</script>
