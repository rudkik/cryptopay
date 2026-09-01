<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\InvalidStateException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UserController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return UserResource::collection(
            User::query()->orderBy('name')->paginate(min(100, max(1, (int) $request->integer('per_page', 25))))
        );
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = User::create($request->validated());

        // `is_active` is a DB default; without refresh() it serialises as null and the
        // admin UI renders a brand-new user as "Inactive".
        $user->refresh();

        return (new UserResource($user))->response()->setStatusCode(201);
    }

    public function show(string $user): UserResource
    {
        return new UserResource(User::query()->whereKey($user)->firstOrFail());
    }

    public function update(UpdateUserRequest $request, string $user): UserResource
    {
        $model = User::query()->whereKey($user)->firstOrFail();

        $data = $request->validated();

        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        $model->update($data);

        return new UserResource($model);
    }

    public function destroy(Request $request, string $user): JsonResponse
    {
        $model = User::query()->whereKey($user)->firstOrFail();

        if ($request->user()?->getKey() === $model->getKey()) {
            throw new InvalidStateException('You cannot delete your own account.');
        }

        $model->delete();

        return response()->json(['ok' => true]);
    }
}
