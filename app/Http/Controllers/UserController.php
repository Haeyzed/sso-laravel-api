<?php

namespace App\Http\Controllers;

use App\Exports\DynamicExport;
use App\Http\Requests\{BulkRequest, ExportRequest, ImportRequest, IndexRequest, UserRequest};
use App\Http\Resources\UserResource;
use App\Imports\DynamicImport;
use App\Jobs\SendExportEmail;
use App\Models\BlockedIp;
use App\Models\User;
use App\Traits\ExceptionHandlerTrait;
use App\Utils\Sqid;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\{JsonResponse, Resources\Json\AnonymousResourceCollection};
use Illuminate\Support\Facades\{DB, Hash};
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Class UserController
 *
 * @tags Users
 */
class UserController extends Controller
{
    use ExceptionHandlerTrait;
    /**
     * Display a listing of the users.
     *
     * @param IndexRequest $request
     * @return JsonResponse|AnonymousResourceCollection
     */
    public function index(IndexRequest $request)
    {
        try {
            $query = User::query()
                ->when($request->with_trashed, fn($q) => $q->withTrashed())
                ->when($request->search, fn($q, $search) => app('search')->apply($q, $search, ['name', 'email', 'username', 'phone']))
                ->when($request->order_by, fn($q, $orderBy) => $q->orderBy($orderBy ?? 'name', $request->order_direction ?? 'asc'))
                ->when($request->start_date && $request->end_date, fn($q) => $q->custom($request->start_date, $request->end_date));
            $users = $query->paginate($request->per_page ?? config('app.per_page'));
            return response()->paginatedSuccess(UserResource::collection($users), 'Users retrieved successfully');
        } catch (Exception $e) {
            return $this->handleException($e, 'fetching users');
        }
    }

    /**
     * Store a newly created user in storage.
     *
     * @param UserRequest $request
     * @return JsonResponse
     */
    public function store(UserRequest $request): JsonResponse
    {
        try {
            return DB::transaction(function () use ($request) {
                $user = User::create($request->validated() + ['password' => Hash::make($request->password)]);
                return response()->created(new UserResource($user), 'User created successfully');
            });
        } catch (Exception $e) {
            return $this->handleException($e, 'creating the user');
        }
    }

    /**
     * Display the specified user.
     *
     * @param string $sqid
     * @return JsonResponse
     */
    public function show(string $sqid): JsonResponse
    {
        try {
            $user = User::findBySqidOrFail($sqid);
            return response()->success(new UserResource($user), 'User retrieved successfully');
        } catch (NotFoundHttpException|ModelNotFoundException $e) {
            return $this->handleNotFoundException($e);
        } catch (Exception $e) {
            return $this->handleException($e, 'fetching the user');
        }
    }

    /**
     * Update the specified user in storage.
     *
     * @param UserRequest $request
     * @param string $sqid
     * @return JsonResponse
     */
    public function update(UserRequest $request, string $sqid): JsonResponse
    {
        try {
            return DB::transaction(function () use ($request, $sqid) {
                $user = User::findBySqidOrFail($sqid);
                $user->update($request->validated());
                return response()->success(new UserResource($user), 'User updated successfully');
            });
        } catch (NotFoundHttpException|ModelNotFoundException $e) {
            return $this->handleNotFoundException($e);
        } catch (Exception $e) {
            return $this->handleException($e, 'updating the user');
        }
    }

    /**
     * Remove the specified user from storage.
     *
     * @param string $sqid
     * @return JsonResponse
     */
    public function destroy(string $sqid): JsonResponse
    {
        try {
            return DB::transaction(function () use ($sqid) {
                $user = User::findBySqidOrFail($sqid);
                $user->delete();
                return response()->success(null, 'User deleted successfully');
            });
        } catch (NotFoundHttpException|ModelNotFoundException $e) {
            return $this->handleNotFoundException($e);
        } catch (Exception $e) {
            return $this->handleException($e, 'deleting the user');
        }
    }

    /**
     * Restore the specified user from storage.
     *
     * @param string $sqid
     * @return JsonResponse
     */
    public function restore(string $sqid): JsonResponse
    {
        try {
            return DB::transaction(function () use ($sqid) {
                $user = User::withTrashed()->findOrFail(Sqid::decode($sqid));
                $user->restore();
                return response()->success(new UserResource($user), 'User restored successfully');
            });
        } catch (NotFoundHttpException|ModelNotFoundException $e) {
            return $this->handleNotFoundException($e);
        } catch (Exception $e) {
            return $this->handleException($e, 'restoring the user');
        }
    }

    /**
     * Bulk delete users from storage.
     *
     * @param BulkRequest $request
     * @return JsonResponse
     */
    public function bulkDelete(BulkRequest $request): JsonResponse
    {
        try {
            return DB::transaction(function () use ($request) {
                $ids = array_map([Sqid::class, 'decode'], $request->input('sqids', []));
                User::whereIn('id', $ids)->delete();
                return response()->success(null, 'Users deleted successfully');
            });
        } catch (NotFoundHttpException|ModelNotFoundException $e) {
            return $this->handleNotFoundException($e);
        } catch (Exception $e) {
            return $this->handleException($e, 'bulk deleting users');
        }
    }

    /**
     * Bulk restore users from storage.
     *
     * @param BulkRequest $request
     * @return JsonResponse
     */
    public function bulkRestore(BulkRequest $request): JsonResponse
    {
        try {
            return DB::transaction(function () use ($request) {
                $ids = array_map([Sqid::class, 'decode'], $request->input('sqids', []));
                $users = User::withTrashed()->whereIn('id', $ids)->restore();
                return response()->success(UserResource::collection($users), 'Users restored successfully');
            });
        } catch (NotFoundHttpException|ModelNotFoundException $e) {
            return $this->handleNotFoundException($e);
        } catch (Exception $e) {
            return $this->handleException($e, 'bulk restoring users');
        }
    }

    /**
     * Force delete the specified user from storage.
     *
     * @param string $sqid
     * @return JsonResponse
     */
    public function forceDelete(string $sqid): JsonResponse
    {
        try {
            return DB::transaction(function () use ($sqid) {
                $user = User::withTrashed()->findOrFail(Sqid::decode($sqid));
                $user->forceDelete();
                return response()->success(null, 'User permanently deleted successfully');
            });
        } catch (NotFoundHttpException|ModelNotFoundException $e) {
            return $this->handleNotFoundException($e);
        } catch (Exception $e) {
            return $this->handleException($e, 'permanently deleting the user');
        }
    }

    /**
     * Import users from a file.
     *
     * @param ImportRequest $request
     * @return JsonResponse
     */
    public function import(ImportRequest $request): JsonResponse
    {
        try {
            $modelClass = 'App\\Models\\' . $request->model;
            $import = new DynamicImport($modelClass, $request->update_existing ?? false);
            Excel::import($import, $request->file('file'));
            return response()->success($request->model . 's imported successfully.');
        } catch (Exception $e) {
            return $this->handleException($e, 'importing ' . $request->model . 's');
        }
    }

    /**
     * Export users to a file and send via email.
     *
     * @param ExportRequest $request
     * @return JsonResponse
     */
    public function export(ExportRequest $request): JsonResponse
    {
        try {
            $modelClass = 'App\\Models\\' . $request->model;
            $fileName = strtolower($request->model) . '_export_' . now()->format('Y-m-d_H-i-s') . '.' . $request->file_type;
            $export = new DynamicExport($modelClass, $request->start_date, $request->end_date, $request->columns);
            Excel::store($export, $fileName, 'local');
            foreach ($request->emails as $email) {
                SendExportEmail::dispatch($email, $fileName, $request->columns, $request->model);
            }
            return response()->success($request->model . ' export initiated. You will receive an email shortly.');
        } catch (Exception $e) {
            return $this->handleException($e, 'exporting ' . $request->model . 's');
        }
    }

    /**
     * Blacklist a user's IP address
     *
     * @param string $sqid
     * @param string $ipAddress
     * @return JsonResponse
     */
    public function blockIp(string $sqid, string $ipAddress): JsonResponse
    {
        try {
            $user = User::findBySqidOrFail($sqid);
            BlockedIP::create([
                'user_id' => $user->id,
                'ip_address' => $ipAddress,
                'reason' => null,
                'blocked_until' => null,
            ]);
            return response()->success("IP address: {$ipAddress} has been blacklisted for user: {$user->name}");
        } catch (NotFoundHttpException|ModelNotFoundException $e) {
            return $this->handleNotFoundException($e);
        } catch (Exception $e) {
            return $this->handleException($e, 'blocking the IP address');
        }
    }

    /**
     * Remove a user's IP address from the blocklist
     *
     * @param string $sqid
     * @param string $ipAddress
     * @return JsonResponse
     */
    public function unblockIp(string $sqid, string $ipAddress): JsonResponse
    {
        try {
            $user = User::findBySqidOrFail($sqid);
            $blockedIp = BlockedIP::where('user_id', $user->id)
                ->where('ip_address', $ipAddress)
                ->firstOrFail();

            $blockedIp->delete();

            return response()->success("IP address: {$ipAddress} has been removed from the blacklist for user: {$user->name}");
        } catch (NotFoundHttpException|ModelNotFoundException $e) {
            return $this->handleNotFoundException($e);
        } catch (Exception $e) {
            return $this->handleException($e, 'unblocking the IP address');
        }
    }
}
