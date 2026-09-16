<?php

namespace App\Http\Controllers\Proffi;

use App\Http\Controllers\Controller;
use App\Http\Requests\Proffi\ListPlacesRequest;
use App\Http\Requests\Proffi\StorePlaceRequest;
use App\Http\Requests\Proffi\UpdatePlaceRequest;
use App\Http\Resources\Proffi\PlaceDetailResource;
use App\Http\Resources\Proffi\PlaceListResource;
use App\Models\ProffiTask;
use App\Services\Proffi\PlaceService;
use App\Services\Proffi\PlaceToRequestDraftService;
use App\Services\Proffi\TaskToPlaceDraftService;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Marvel\Database\Models\Place;
use Marvel\Database\Models\User;

class ProffiPlaceController extends Controller
{
    public function __construct(
        private readonly PlaceService $places,
        private readonly PlaceToRequestDraftService $placeToRequest,
        private readonly TaskToPlaceDraftService $taskToPlace,
    ) {
    }

    public function index(ListPlacesRequest $request)
    {
        return PlaceListResource::collection(
            $this->places->publicList($request->validated(), $this->viewerId($request))
        );
    }

    public function show(Request $request, Place $place): PlaceDetailResource
    {
        return new PlaceDetailResource($this->places->detail($place, $this->viewerId($request)));
    }

    public function store(StorePlaceRequest $request)
    {
        $place = $this->places->create($request->user(), $request->validated());

        return (new PlaceDetailResource($place))->response()->setStatusCode(201);
    }

    public function update(UpdatePlaceRequest $request, Place $place): PlaceDetailResource
    {
        $this->authorize('update', $place);

        return new PlaceDetailResource(
            $this->places->update($place, $request->user(), $request->validated())
        );
    }

    public function destroy(Request $request, Place $place)
    {
        $this->authorize('delete', $place);
        $this->places->delete($place);

        return ['ok' => true];
    }

    public function mine(ListPlacesRequest $request)
    {
        return PlaceListResource::collection(
            $this->places->mine($request->validated(), (int) $request->user()->id)
        );
    }

    public function userPlaces(ListPlacesRequest $request, User $user)
    {
        return PlaceListResource::collection(
            $this->places->byUser($request->validated(), (int) $user->id, $this->viewerId($request))
        );
    }

    public function favorite(Request $request, Place $place)
    {
        $this->places->favorite($place, (int) $request->user()->id);

        return ['ok' => true, 'place_id' => (string) $place->id];
    }

    public function unfavorite(Request $request, Place $place)
    {
        $this->places->unfavorite($place, (int) $request->user()->id);

        return ['ok' => true];
    }

    public function createRequest(Request $request, Place $place)
    {
        $data = $request->validate([
            'idempotency_key' => ['nullable', 'string', 'max:128'],
        ]);
        $result = $this->placeToRequest->create(
            $place,
            $request->user(),
            $data['idempotency_key'] ?? null
        );

        return response()->json($result, 201);
    }

    public function createFromTask(Request $request, ProffiTask $task)
    {
        $place = $this->taskToPlace->create($task, $request->user());

        return (new PlaceDetailResource($place))->response()->setStatusCode(201);
    }

    private function viewerId(Request $request): ?int
    {
        if ($request->user()) {
            return (int) $request->user()->id;
        }
        if (!$request->bearerToken()) {
            return null;
        }

        try {
            return PersonalAccessToken::findToken($request->bearerToken())?->tokenable?->id;
        } catch (\Throwable) {
            return null;
        }
    }
}
