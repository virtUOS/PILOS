<template>
  <div>
    <div class="flex flex-col-reverse justify-between gap-2 lg:flex-row">
      <div class="flex grow flex-col justify-end gap-2 lg:flex-row">
        <InputGroup v-if="userPermissions.can('manageSettings', props.room)">
          <InputGroupAddon aria-hidden="true">
            <i class="fa-solid fa-filter"></i>
          </InputGroupAddon>
          <Select
            v-model="filter"
            :disabled="isBusy"
            :options="filterOptions"
            option-label="name"
            option-value="value"
            data-test="filter-dropdown"
            :aria-label="$t('rooms.meeting_summaries.filter_aria')"
            @change="loadData(1)"
          />
        </InputGroup>
        <Button
          :disabled="isBusy"
          :icon="
            sortOrder === 1
              ? 'fa-solid fa-arrow-up-short-wide'
              : 'fa-solid fa-arrow-down-wide-short'
          "
          :aria-label="
            sortOrder === 1
              ? $t('rooms.meeting_summaries.sort_ascending')
              : $t('rooms.meeting_summaries.sort_descending')
          "
          severity="secondary"
          data-test="room-meeting-summaries-sort-button"
          @click="toggleSortOrder"
        />
      </div>
      <div class="flex justify-end gap-2">
        <!-- Reload list -->
        <Button
          v-tooltip="$t('app.reload')"
          data-test="room-meeting-summaries-reload-button"
          class="shrink-0"
          :aria-label="$t('rooms.meeting_summaries.reload_aria')"
          severity="secondary"
          :disabled="isBusy"
          icon="fa-solid fa-sync"
          @click="loadData()"
        />
      </div>
    </div>

    <OverlayComponent :show="isBusy || loadingError" :z-index="1">
      <template #overlay>
        <LoadingRetryButton :error="loadingError" @reload="loadData()" />
      </template>

      <DataView
        :total-records="paginator.getTotalRecords()"
        :rows="paginator.getRows()"
        :first="paginator.getFirst()"
        :value="summaries"
        lazy
        data-key="id"
        paginator
        :paginator-template="paginator.getTemplate()"
        :current-page-report-template="paginator.getCurrentPageReportTemplate()"
        :loading="isBusy"
        class="mt-6"
        @update:first="paginator.setFirst($event)"
        @page="onPage"
      >
        <!-- Show message on empty list -->
        <template #empty>
          <div>
            <div v-if="!isBusy && !loadingError">
              <InlineNote v-if="paginator.isEmptyUnfiltered()">{{
                $t("rooms.meeting_summaries.nodata")
              }}</InlineNote>
              <InlineNote v-else>{{ $t("app.filter_no_results") }}</InlineNote>
            </div>
          </div>
        </template>

        <template #list="slotProps">
          <div>
            <div v-for="item in slotProps.items" :key="item.id">
              <div
                data-test="room-meeting-summary-item"
                class="flex flex-col justify-between gap-4 border-t border-surface py-4 md:flex-row"
              >
                <div class="flex flex-col gap-2">
                  <p class="m-0 text-lg font-semibold">
                    {{ $d(new Date(item.start), "datetimeShort") }}
                  </p>
                  <div class="flex flex-col items-start gap-2">
                    <div
                      v-if="item.end"
                      class="flex flex-row items-center gap-2"
                    >
                      <i class="fa-solid fa-hourglass" />
                      <p class="m-0 text-sm">
                        {{
                          dateDiff.format(
                            new Date(item.start),
                            new Date(item.end),
                          )
                        }}
                      </p>
                    </div>
                    <div
                      v-if="userPermissions.can('manageSettings', props.room)"
                      class="flex flex-row items-center gap-2"
                    >
                      <i class="fa-solid fa-lock"></i>
                      <RoomRecordingAccessBadge :access="item.access" />
                    </div>
                    <small
                      v-if="item.type === 'document'"
                      class="text-muted-color"
                      data-test="room-meeting-summary-document-hint"
                      >{{
                        isOwner
                          ? $t("rooms.meeting_summaries.document_hint_owner")
                          : $t("rooms.meeting_summaries.document_hint")
                      }}</small
                    >
                  </div>
                </div>
                <div
                  class="flex shrink-0 flex-row items-start justify-end gap-1"
                >
                  <!-- Link to the document in the sink -->
                  <Button
                    v-if="item.type === 'document'"
                    v-tooltip="$t('rooms.meeting_summaries.open_document')"
                    as="a"
                    :href="item.document_url"
                    target="_blank"
                    rel="noopener noreferrer"
                    icon="fa-solid fa-arrow-up-right-from-square"
                    :aria-label="
                      $t('rooms.meeting_summaries.open_document_aria', {
                        date: $d(new Date(item.start), 'datetimeShort'),
                      })
                    "
                    data-test="room-meeting-summaries-open-document-button"
                  />

                  <!-- Summary text stored in the room, as a Markdown file -->
                  <RoomTabMeetingSummariesDownloadButton
                    v-else
                    :room-id="props.room.id"
                    :summary-id="item.id"
                    :start="item.start"
                    :room-auth-token="props.roomAuthToken"
                    :disabled="isBusy"
                    @not-found="loadData()"
                  />

                  <RoomTabMeetingSummariesEditButton
                    v-if="userPermissions.can('manageSettings', props.room)"
                    :room-id="props.room.id"
                    :summary-id="item.id"
                    :start="item.start"
                    :access="item.access"
                    :disabled="isBusy"
                    @edited="loadData()"
                    @not-found="loadData()"
                  />

                  <RoomTabMeetingSummariesDeleteButton
                    v-if="userPermissions.can('manageSettings', props.room)"
                    :room-id="props.room.id"
                    :summary-id="item.id"
                    :start="item.start"
                    :disabled="isBusy"
                    @deleted="loadData()"
                    @not-found="loadData()"
                  />
                </div>
              </div>
            </div>
          </div>
        </template>
      </DataView>
    </OverlayComponent>

    <Message
      class="mt-2"
      severity="secondary"
      aria-live="off"
      role="presentation"
      :pt="{
        content: {
          'data-test': 'retention-period-message',
        },
      }"
    >
      <div class="leading-3 font-normal">
        <p class="text-xl font-semibold">
          {{ $t("rooms.meeting_summaries.retention_period.title") }}
        </p>
        <br />
        <span v-if="retentionPeriod !== -1">{{
          $t("rooms.meeting_summaries.retention_period.days", {
            days: retentionPeriod,
          })
        }}</span>
        <span v-else>{{
          $t("rooms.meeting_summaries.retention_period.unlimited")
        }}</span>
      </div>
    </Message>
  </div>
</template>
<script setup>
import { computed, onMounted, ref } from "vue";
import { useApi } from "../composables/useApi.js";
import { useUserPermissions } from "../composables/useUserPermission.js";
import { useSettingsStore } from "../stores/settings.js";
import { useAuthStore } from "../stores/auth";
import { usePaginator } from "../composables/usePaginator.js";
import { useDateDiff } from "../composables/useDateDiff.js";
import { useI18n } from "vue-i18n";
import { onRoomHasChanged } from "../composables/useRoomHelpers.js";
import {
  HTTP_ERROR_ROOM_INVALID_AUTH_TOKEN,
  HTTP_ERROR_ROOM_REQUIRE_CODE,
} from "../constants/httpCustomErrorMessages.js";
import {
  HTTP_STATUS_FORBIDDEN,
  HTTP_STATUS_UNAUTHORIZED,
} from "../constants/httpStatusCodes.js";
import RoomTabMeetingSummariesDownloadButton from "./RoomTabMeetingSummariesDownloadButton.vue";
import RoomTabMeetingSummariesEditButton from "./RoomTabMeetingSummariesEditButton.vue";
import RoomTabMeetingSummariesDeleteButton from "./RoomTabMeetingSummariesDeleteButton.vue";

const props = defineProps({
  room: {
    type: Object,
    required: true,
  },
  roomAuthToken: {
    type: Object,
    default: null,
  },
});

const emit = defineEmits([
  "invalidRoomAuthToken",
  "requireCode",
  "guestsNotAllowed",
]);

const api = useApi();
const userPermissions = useUserPermissions();
const settingsStore = useSettingsStore();
const authStore = useAuthStore();
const paginator = usePaginator();
const dateDiff = useDateDiff();
const { t } = useI18n();

const isBusy = ref(false);
const loadingError = ref(false);

const summaries = ref([]);
const sortOrder = ref(0);
const filter = ref("all");

const isOwner = computed(() => {
  return (
    authStore.currentUser != null &&
    authStore.currentUser.id === props.room.owner?.id
  );
});

const retentionPeriod = computed(() =>
  settingsStore.getSetting("recording.recording_retention_period"),
);

const filterOptions = computed(() => [
  { name: t("rooms.recordings.filter.all"), value: "all" },
  {
    name: t("rooms.recordings.filter.everyone_access"),
    value: "everyone_access",
  },
  {
    name: t("rooms.recordings.filter.participant_access"),
    value: "participant_access",
  },
  {
    name: t("rooms.recordings.filter.moderator_access"),
    value: "moderator_access",
  },
  { name: t("rooms.recordings.filter.owner_access"), value: "owner_access" },
]);

const toggleSortOrder = () => {
  sortOrder.value = sortOrder.value === 1 ? 0 : 1;
  loadData(1);
};

/**
 * Reload the meeting summary list from the api
 */
function loadData(page = null) {
  isBusy.value = true;
  loadingError.value = false;

  const config = {
    params: {
      page: page || paginator.getCurrentPage(),
      sort_direction: sortOrder.value === 1 ? "asc" : "desc",
      filter: filter.value === "all" ? null : filter.value,
    },
  };

  if (props.roomAuthToken) {
    config.params.room_auth_token = props.roomAuthToken.id;
    config.params.room_auth_token_type = props.roomAuthToken.type;
  }

  api
    .call("rooms/" + props.room.id + "/meetingSummaries", config)
    .then((response) => {
      summaries.value = response.data.data;
      paginator.updateMeta(response.data.meta).then(() => {
        if (paginator.isOutOfRange()) {
          loadData(paginator.getLastPage());
        }
      });
    })
    .catch((error) => {
      if (error.response) {
        // Room auth token is invalid
        if (
          error.response.status === HTTP_STATUS_UNAUTHORIZED &&
          error.response.data.message === HTTP_ERROR_ROOM_INVALID_AUTH_TOKEN
        ) {
          return emit("invalidRoomAuthToken");
        }

        // Forbidden, require access code
        if (
          error.response.status === HTTP_STATUS_FORBIDDEN &&
          error.response.data.message === HTTP_ERROR_ROOM_REQUIRE_CODE
        ) {
          return emit("requireCode");
        }
      }
      loadingError.value = true;
      paginator.revertFirst();
      api.error(error, { redirectOnUnauthenticated: false });
    })
    .finally(() => {
      isBusy.value = false;
    });
}

function onPage(event) {
  loadData(event.page + 1);
}

onMounted(() => {
  loadData();
});

onRoomHasChanged(
  () => props.room,
  () => loadData(),
);
</script>
