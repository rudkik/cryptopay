<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\UserRole;
use App\Exceptions\InvalidStateException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UserController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

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

        $this->audit->log('user.created', $user, [
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
            'is_active' => $user->is_active,
        ]);

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

        $this->assertAdminsRemain(
            $model,
            role: array_key_exists('role', $data) ? UserRole::from((string) $data['role']) : null,
            isActive: array_key_exists('is_active', $data) ? (bool) $data['is_active'] : null,
        );

        $before = $model->getAttributes();
        $model->update($data);

        $this->audit->log('user.updated', $model, AuditLogger::diff($before, $model));

        return new UserResource($model);
    }

    public function destroy(Request $request, string $user): JsonResponse
    {
        $model = User::query()->whereKey($user)->firstOrFail();

        if ($request->user()?->getKey() === $model->getKey()) {
            throw new InvalidStateException('You cannot delete your own account.');
        }

        $this->assertAdminsRemain($model, role: null, isActive: false);

        $this->audit->log('user.deleted', $model, [
            'email' => $model->email,
            'role' => $model->role->value,
        ]);

        $model->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Every mutating admin route sits behind role=admin + is_active, so losing
     * the last active admin locks the whole panel down with no way back in
     * short of a shell on the container. Demoting, deactivating or deleting
     * that account is refused.
     *
     * @param  UserRole|null  $role  the role the user would end up with, if it is changing
     * @param  bool|null  $isActive  the active flag the user would end up with, if it is changing
     */
    private function assertAdminsRemain(User $model, ?UserRole $role, ?bool $isActive): void
    {
        $wasAdmin = $model->role === UserRole::Admin && $model->is_active;

        if (! $wasAdmin) {
            return;
        }

        $staysAdmin = ($role ?? $model->role) === UserRole::Admin
            && ($isActive ?? $model->is_active);

        if ($staysAdmin) {
            return;
        }

        $others = User::query()
            ->where('role', UserRole::Admin->value)
            ->where('is_active', true)
            ->whereKeyNot($model->getKey())
            ->count();

        if ($others === 0) {
            throw new InvalidStateException(
                'This is the last active admin; promote or activate another admin first.',
            );
        }
    }
}
