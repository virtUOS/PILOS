---
title: Meeting summaries
description: Guide to setting up AI meeting summaries with an external summary service
---

:::note

This is a new experimental feature and therefore not enabled by default.

:::

## Introduction

PILOS can ask an external meeting summary service to transcribe and summarize meetings.
The summary is delivered to the room owner by the summary service, for example as a document in a document editor.
PILOS itself does not process the recording; it only marks the meeting at BigBlueButton and tells the summary service who the owner is.
Meeting summaries do not require the recording setting of the room.

When a meeting starts in a room with meeting summaries enabled, PILOS:

1. sends `record=true` to BigBlueButton, even if the recording of the video conference is disabled for the room (BigBlueButton only exports meetings with recording marks),
2. adds the meta parameters `meta_<prefix>-v=1`, `meta_<prefix>-enabled=true`, `meta_<prefix>-type` and `meta_<prefix>-sink` to the BigBlueButton `create` call,
3. stores the internal meeting ID returned by BigBlueButton (the ID of the later recording),
4. registers the room owner with the summary service in a queued job (`PUT /api/v1/registrations/{internal meeting id}`).

The BigBlueButton server needs a recording exporter that sends the raw recordings of these meetings to the summary service.
Only meetings with both the meta parameters and a registration are summarized.

When a room is deleted, PILOS cancels the registrations of its meetings in a queued job (`DELETE /api/v1/registrations/{internal meeting id}`).
A summary that has already been delivered is not withdrawn; it belongs to the owner.
Transferring the room ownership does not change existing registrations: the summary goes to the owner at the time the meeting was started.

## Requirements

- The summary service is reachable from PILOS, and a bearer token for this PILOS instance is set up there.
- Users log in via [OpenID Connect](./01-external-authentication.md). The summary service identifies the owner by the OpenID Connect subject (`sub`), which PILOS stores at each OpenID Connect login.
  The meeting summary setting is unavailable to a room owner until they have logged in via OpenID Connect once.
- The queue workers (Horizon) are running, as registrations are sent by queued jobs.

## Setup

### 1. Configure PILOS

Enable meeting summaries by adding the following environment variables to the `.env` file:

```shell
RECAP_ENABLED=true
RECAP_SERVICE_URL=https://recap.domain.tld
RECAP_FRONTEND_TOKEN="token-of-this-pilos-instance"
```

- `RECAP_SERVICE_URL` is the base URL of the summary service; if the API is in a subdirectory, include the full path.
- The feature stays disabled unless `RECAP_ENABLED`, `RECAP_SERVICE_URL` and `RECAP_FRONTEND_TOKEN` are all set.
- `RECAP_META_PREFIX` must be the same prefix as configured in the recording exporter on the BigBlueButton servers.
  It must be a lowercase letter followed by 1-15 lowercase letters or digits; an invalid prefix disables the feature and logs a warning.

### 2. Configure room types

Meeting summaries are configured per room type in the admin UI:

- **Create a meeting summary (AI)**: Default value of the room setting, and whether it is enforced. A room type can enforce meeting summaries off, but it cannot enforce them on.
- **Meeting type**: Name of the meeting type at the summary service, which selects the summary template (e.g. `meeting-summary`).
- **Summary destination**: Name of the sink profile at the summary service the summaries are delivered to (e.g. `docs-main`).

Both names consist of lowercase letters, digits and hyphens and must be configured at the summary service.
Meetings in rooms of a room type without meeting type or destination are never summarized.

Custom create parameters of a room type must not contain `meta_<prefix>-*` parameters, so a room type cannot opt rooms in.

### 3. Room setting

The room owner, and only the room owner, can enable **Create a meeting summary (AI)** in the **Meeting summary (AI)** section of the room settings.
Co-owners and administrators cannot change it.

A meeting is summarized only if, when it starts:

- the feature is enabled,
- the room owner has an OpenID Connect subject,
- the room setting is enabled (and not enforced off by the room type),
- the room type has a meeting type and a summary destination.

The recording setting of the room is not one of these conditions.

Moderators decide which parts of the meeting are summarized: they start and stop the capture with the record button of BigBlueButton.
**Automatic recording start** keeps the value of the room setting, also in meetings with a summary only.
Without recording marks, BigBlueButton does not export the meeting and no summary is created.

### Meetings with a summary but without recording

If the room has meeting summaries enabled but the recording of the video conference disabled:

- The camera video of the participants is never recorded; PILOS does not ask for the consent to record the own video.
- PILOS does not import the playback recording of the meeting. BigBlueButton still creates it, as the meeting is recorded for the summary service,
  but when the recording is imported, PILOS deletes it from the spool directory without storing it and logs one info line with the meeting ID.
  Participants never see a recording nobody asked for.

Meetings with both recording and meeting summary enabled are recorded and imported as before.

## Consent

Participants give one consent before starting or joining a meeting with recording or meeting summary; PILOS refuses the request without it.
The text depends on the meeting:

| Meeting                    | Texts                                                        | Default (English)                                                                                                                                               |
| -------------------------- | ------------------------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Summary only               | `rooms.recap_info`, `rooms.recap_accept`                     | An AI may transcribe and summarize the audio of this meeting. No recording is published. / I consent to the transcription and AI summary of the audio.          |
| Recording and summary      | `rooms.recording_recap_info`, `rooms.recording_recap_accept` | This room may be recorded, and an AI may transcribe and summarize the audio. / I consent to the recording and to the transcription and AI summary of the audio. |
| Recording only (unchanged) | `rooms.recording_info`, `rooms.recording_accept`             | This room may be recorded. / I consent to the recording.                                                                                                        |

The hint `rooms.recording_hint` (mute the microphone to avoid being recorded) is shown in all three cases.
The optional consent to record the own video (`rooms.recording_video_accept`) is only asked in meetings with recording.

Customize the texts as described in [Locales](../09-customisation/02-locales.md), for example to name the summary service or the data protection notice:

```php
'recap_info' => 'The audio of this meeting may be transcribed and summarized by an AI service of the university. No recording is published.',
```

## Data flow and privacy

| Data                                                                      | Sent to                                       | Personal data                                  |
| ------------------------------------------------------------------------- | --------------------------------------------- | ---------------------------------------------- |
| `meta_<prefix>-*` parameters (version, opt-in, meeting type, destination) | BigBlueButton, part of the recording metadata | none; may be visible to meeting participants   |
| Registration: meeting ID, internal meeting ID, owner `sub` and email      | summary service                               | owner OpenID Connect subject and email address |
| Cancellation on room deletion: internal meeting ID                        | summary service                               | none                                           |

- PILOS stores the OpenID Connect subject in the users table, and the meeting summary flag and the internal meeting ID in the meetings table. They are deleted with the user or the meeting.
- PILOS does not store the playback recording of a meeting with a summary but without recording; it deletes the spool file on import.
- The queued jobs contain only model IDs or the internal meeting ID, no subject or email.
- Logs contain only meeting and internal meeting IDs, HTTP status codes and problem types, never the subject, email or token.
- How long the summary service keeps the registration, recording and summary is configured there.

## Retries

Registrations and cancellations are retried for up to one day (after 30 seconds, 60 seconds and then every 5 minutes) on network errors, HTTP 429 and HTTP 5xx.
Other client errors (e.g. 400, 401, 409) are logged with status and problem type, and the job fails without retry.
A late registration is accepted by the summary service.
A cancellation answered with 404 or with 409 `already-delivered` is final.

## Configuration Options

| Option                         | Default Value | Description                                                                                            |
| ------------------------------ | ------------- | ------------------------------------------------------------------------------------------------------ |
| `RECAP_ENABLED`                | `false`       | Enable meeting summaries (also requires `RECAP_SERVICE_URL` and `RECAP_FRONTEND_TOKEN`)                |
| `RECAP_META_PREFIX`            | `recap`       | Prefix of the BigBlueButton meta parameters, must equal the prefix of the recording exporter           |
| `RECAP_SERVICE_URL`            | `null`        | Base URL of the meeting summary service                                                                |
| `RECAP_FRONTEND_TOKEN`         | `null`        | Bearer token of this PILOS instance at the meeting summary service                                     |
| `RECAP_SERVER_CONNECT_TIMEOUT` | `20`          | Maximum time to wait to establish a connection to the meeting summary service                          |
| `RECAP_SERVER_TIMEOUT`         | `10`          | Maximum time to wait for a response of the meeting summary service, once the connection is established |
