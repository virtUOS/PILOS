---
title: Meeting summaries
description: Guide to setting up AI meeting summaries of recorded meetings with an external summary service
---

:::note

This is a new experimental feature and therefore not enabled by default.

:::

## Introduction

PILOS can ask an external meeting summary service to transcribe and summarize recorded meetings.
The summary is delivered to the room owner by the summary service, for example as a document in a document editor.
PILOS itself does not process the recording; it only marks the meeting at BigBlueButton and tells the summary service who the owner is.

When a meeting starts in a room with meeting summaries enabled, PILOS:

1. adds the meta parameters `meta_<prefix>-v=1`, `meta_<prefix>-enabled=true`, `meta_<prefix>-type` and `meta_<prefix>-sink` to the BigBlueButton `create` call,
2. stores the internal meeting ID returned by BigBlueButton (the ID of the later recording),
3. registers the room owner with the summary service in a queued job (`PUT /api/v1/registrations/{internal meeting id}`).

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

The room owner, and only the room owner, can enable **Create a meeting summary (AI)** in the recording section of the room settings.
Co-owners and administrators cannot change it.

A meeting is summarized only if, when it starts:

- the feature is enabled,
- the room owner has an OpenID Connect subject,
- the room setting is enabled (and not enforced off by the room type),
- the recording of the video conference is enabled for the room,
- the room type has a meeting type and a summary destination.

The recording must then actually be started in the meeting (or start automatically); without recording marks, BigBlueButton does not export the meeting.

## Consent

There is no separate consent for meeting summaries. The existing recording consent applies to all participants.
As the recording may be transcribed and summarized by an AI, the recording consent text should mention this.
Customize the texts `rooms.recording_info` and `rooms.recording_accept` as described in [Locales](../09-customisation/02-locales.md), for example:

```php
'recording_info' => 'This room may be recorded. The recording may be transcribed and summarized by an AI.',
```

## Data flow and privacy

| Data                                                                      | Sent to                                       | Personal data                                  |
| ------------------------------------------------------------------------- | --------------------------------------------- | ---------------------------------------------- |
| `meta_<prefix>-*` parameters (version, opt-in, meeting type, destination) | BigBlueButton, part of the recording metadata | none; may be visible to meeting participants   |
| Registration: meeting ID, internal meeting ID, owner `sub` and email      | summary service                               | owner OpenID Connect subject and email address |
| Cancellation on room deletion: internal meeting ID                        | summary service                               | none                                           |

- PILOS stores the OpenID Connect subject in the users table, and the meeting summary flag and the internal meeting ID in the meetings table. They are deleted with the user or the meeting.
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
