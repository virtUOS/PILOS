<?php

declare(strict_types=1);

namespace App\Http\Controllers\api\v1;

use App\Enums\RecordingAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\MeetingSummaryIndexRequest;
use App\Http\Requests\UpdateMeetingSummaryRequest;
use App\Http\Resources\MeetingSummaryResource;
use App\Models\MeetingSummary;
use App\Models\Room;
use App\Settings\GeneralSettings;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\HeaderUtils;

class MeetingSummaryController extends Controller
{
    /**
     * Columns of the list, without the summary text
     *
     * @var list<string>
     */
    private const LIST_COLUMNS = ['id', 'room_id', 'meeting_id', 'access', 'start', 'end', 'document_url', 'delivered_at'];

    public function index(Room $room, MeetingSummaryIndexRequest $request, GeneralSettings $generalSettings): AnonymousResourceCollection
    {
        $additional = [];

        // Sort direction, fallback/default is newest first
        $sortOrder = match ($request->query('sort_direction')) {
            'asc' => 'ASC',
            default => 'DESC',
        };

        // Filter, default is no filter
        $filter = match ($request->query('filter')) {
            'everyone_access' => RecordingAccess::EVERYONE,
            'participant_access' => RecordingAccess::PARTICIPANT,
            'moderator_access' => RecordingAccess::MODERATOR,
            'owner_access' => RecordingAccess::OWNER,
            default => null,
        };

        $resource = $room->meetingSummaries()->select(self::LIST_COLUMNS)->orderBy('start', $sortOrder)->orderBy('id');

        // If user is not allowed to view all summaries, only query summaries that should be visible to the user
        if (! Gate::allows('viewAllMeetingSummaries', $room)) {
            $allowedAccess = [RecordingAccess::EVERYONE];

            if ($room->isModerator(Auth::user())) {
                $allowedAccess[] = RecordingAccess::MODERATOR;
                $allowedAccess[] = RecordingAccess::PARTICIPANT;
            } elseif ($room->isMember(Auth::user())) {
                $allowedAccess[] = RecordingAccess::PARTICIPANT;
            }

            $resource = $resource->whereIn('access', $allowedAccess);
        }

        // count all before applying filters
        $additional['meta']['total_no_filter'] = $resource->count();

        if ($filter) {
            $resource = $resource->where('access', $filter);
        }

        return MeetingSummaryResource::collection($resource->paginate($generalSettings->pagination_page_size))->additional($additional);
    }

    /**
     * Download the summary text as a Markdown file; only for summaries stored in PILOS (not for document links)
     */
    public function download(Room $room, MeetingSummary $meetingSummary, GeneralSettings $generalSettings): Response
    {
        $this->authorize('viewMeetingSummary', [$room, $meetingSummary]);

        if ($meetingSummary->content === null) {
            abort(404);
        }

        // ASCII file name from the room ID and the local start time, e.g. meeting-summary-abc-def-123-2026-11-12-0930.md
        $timezone = Auth::user()?->timezone ?? $generalSettings->default_timezone;
        $start = $meetingSummary->start->copy()->setTimezone($timezone)->format('Y-m-d-Hi');
        $filename = preg_replace('/[^A-Za-z0-9_-]/', '-', 'meeting-summary-'.$room->id.'-'.$start).'.md';

        return response($meetingSummary->content, 200, [
            'Content-Type' => 'text/markdown; charset=utf-8',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function update(UpdateMeetingSummaryRequest $request, Room $room, MeetingSummary $meetingSummary): MeetingSummaryResource
    {
        $meetingSummary->access = $request->enum('access', RecordingAccess::class);
        $meetingSummary->save();

        return new MeetingSummaryResource($meetingSummary);
    }

    public function destroy(Room $room, MeetingSummary $meetingSummary): Response
    {
        Log::info('Deleted meeting summary {summary} in room {room}', ['room' => $room->getLogLabel(), 'summary' => $meetingSummary->getLogLabel()]);

        $meetingSummary->delete();

        return response()->noContent();
    }
}
