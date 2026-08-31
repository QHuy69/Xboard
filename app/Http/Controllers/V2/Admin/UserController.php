<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UserFetch;
use App\Http\Requests\Admin\UserGenerate;
use App\Http\Requests\Admin\UserSendMail;
use App\Http\Requests\Admin\UserUpdate;
use App\Jobs\SendEmailJob;
use App\Models\Plan;
use App\Models\User;
use App\Services\AuthService;
use App\Services\NodeSyncService;
use App\Services\Plugin\HookManager;
use App\Services\UserService;
use App\Traits\QueryOperators;
use App\Utils\Helper;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UserController extends Controller
{
    use QueryOperators;

    private const USER_UPDATE_SUCCEEDED = 'updated';
    private const USER_UPDATE_USER_MISSING = 'user_missing';
    private const USER_UPDATE_REFERRER_MISSING = 'referrer_missing';
    private const USER_UPDATE_REFERRAL_CYCLE = 'referral_cycle';

    public function resetSecret(Request $request)
    {
        $user = User::find($request->input('id'));
        if (!$user)
            return $this->fail([400202, __('The user does not exist')]);
        $user->token = Helper::guid();
        $user->uuid = Helper::guid(true);
        $result = $user->save();

        if ($result) {
            HookManager::call('admin.user.secret.reset', [
                'user' => $user,
                'request' => $request,
            ]);
        }

        return $this->success($result);
    }

    // Apply filters and sorts to the query builder.
    private function applyFiltersAndSorts(Request $request, Builder|QueryBuilder $builder): void
    {
        $this->applyFilters($request, $builder);
        $this->applySorting($request, $builder);
    }

    // Apply filters to the query builder.
    private function applyFilters(Request $request, Builder|QueryBuilder $builder): void
    {
        if (!$request->has('filter')) {
            return;
        }

        collect($request->input('filter'))->each(function ($filter) use ($builder) {
            $field = $filter['id'];
            $value = $filter['value'];
            $logic = strtolower($filter['logic'] ?? 'and');

            if ($logic === 'or') {
                $builder->orWhere(function ($query) use ($field, $value) {
                    $this->buildFilterQuery($query, $field, $value);
                });
            } else {
                $builder->where(function ($query) use ($field, $value) {
                    $this->buildFilterQuery($query, $field, $value);
                });
            }
        });
    }

    // Build one filter query condition.
    private function buildFilterQuery(Builder|QueryBuilder $query, string $field, mixed $value): void
    {
        // Handle relationship filters.
        if (str_contains($field, '.')) {
            if (!method_exists($query, 'whereHas')) {
                return;
            }
            [$relation, $relationField] = explode('.', $field);
            $query->whereHas($relation, function ($q) use ($relationField, $value) {
                if (is_array($value)) {
                    $q->whereIn($relationField, $value);
                } else if (is_string($value) && str_contains($value, ':')) {
                    [$operator, $filterValue] = explode(':', $value, 2);
                    $this->applyQueryCondition($q, $relationField, $operator, $filterValue);
                } else {
                    $q->where($relationField, 'like', "%{$value}%");
                }
            });
            return;
        }

        // Handle array values with an IN query.
        if (is_array($value)) {
            $query->whereIn($field === 'group_ids' ? 'group_id' : $field, $value);
            return;
        }

        // Handle operator-based filters.
        if (!is_string($value) || !str_contains($value, ':')) {
            $query->where($field, 'like', "%{$value}%");
            return;
        }

        [$operator, $filterValue] = explode(':', $value, 2);

        // Convert numeric strings to the appropriate type.
        if (is_numeric($filterValue)) {
            $filterValue = strpos($filterValue, '.') !== false
                ? (float) $filterValue
                : (int) $filterValue;
        }

        // Handle calculated fields.
        $queryField = match ($field) {
            'total_used' => DB::raw('(u + d)'),
            default => $field
        };

        $this->applyQueryCondition($query, $queryField, $operator, $filterValue);
    }

    // Apply sorting rules to the query builder.
    private function applySorting(Request $request, Builder|QueryBuilder $builder): void
    {
        if (!$request->has('sort')) {
            return;
        }

        collect($request->input('sort'))->each(function ($sort) use ($builder) {
            $field = $sort['id'];
            $direction = $sort['desc'] ? 'DESC' : 'ASC';
            $builder->orderBy($field, $direction);
        });
    }

    // Resolve bulk operation scope and normalize user_ids.
    private function resolveScope(Request $request): array
    {
        $scope = $request->input('scope');
        $userIds = $request->input('user_ids');

        $hasSelection = is_array($userIds) && count(array_filter($userIds, static fn($v) => is_numeric($v))) > 0;
        $hasFilter = $request->has('filter') && !empty($request->input('filter'));

        if (!in_array($scope, ['selected', 'filtered', 'all'], true)) {
            if ($hasSelection) {
                $scope = 'selected';
            } elseif ($hasFilter) {
                $scope = 'filtered';
            } else {
                $scope = 'all';
            }
        }

        $normalizedIds = [];
        if ($scope === 'selected') {
            $normalizedIds = is_array($userIds) ? $userIds : [];
            $normalizedIds = array_values(array_unique(array_map(static function ($v) {
                return is_numeric($v) ? (int) $v : null;
            }, $normalizedIds)));
            $normalizedIds = array_values(array_filter($normalizedIds, static fn($v) => is_int($v)));
        }

        return [
            'scope' => $scope,
            'user_ids' => $normalizedIds,
        ];
    }

    // Fetch paginated user list (filters + sorting).
    public function fetch(UserFetch $request)
    {
        $current = $request->input('current', 1);
        $pageSize = $request->input('pageSize', 10);

        $userModel = User::query()
            ->with(['plan:id,name', 'invite_user:id,email', 'group:id,name'])
            ->select((new User())->getTable() . '.*')
            ->selectRaw('(u + d) as total_used');

        $userModel = HookManager::filter('admin.user.fetch.query', $userModel, $request);

        $this->applyFiltersAndSorts($request, $userModel);

        $users = $userModel->orderBy('id', 'desc')
            ->paginate($pageSize, ['*'], 'page', $current);

        $users->getCollection()->transform(function ($user): array {
            return self::transformUserData($user);
        });

        return $this->paginate($users);
    }

    // Transform user fields for API response.
    public static function transformUserData(User $user): array
    {
        $model = $user;
        $user = $user->toArray();
        $user['balance'] = $user['balance'] / 100;
        $user['commission_balance'] = $user['commission_balance'] / 100;
        // Keep the role field stable for old records and JSON clients even
        // when a database driver returns booleans as 0/1 integers.
        $user['is_reseller'] = (bool) ($user['is_reseller'] ?? false);
        $user['subscribe_url'] = Helper::getSubscribeUrl($user['token']);
        return HookManager::filter('admin.user.transform', $user, $model);
    }

    public function getUserInfoById(Request $request)
    {
        $request->validate([
            'id' => 'required|numeric'
        ], [
            'id.required' => __('User ID is required')
        ]);
        $user = User::find($request->input('id'))->load('invite_user');
        $user = HookManager::filter('admin.user.detail', $user, $request);
        return $this->success($user);
    }

    public function update(UserUpdate $request)
    {
        $params = $request->validated();

        $user = User::find($request->input('id'));
        if (!$user) {
            return $this->fail([400202, __('The user does not exist')]);
        }
        if (isset($params['email'])) {
            if (User::byEmail($params['email'])->first() && $user->email !== $params['email']) {
                return $this->fail([400201, __('Email already exists')]);
            }
        }
        // Handle password changes.
        if (isset($params['password'])) {
            $params['password'] = password_hash($params['password'], PASSWORD_DEFAULT);
            $params['password_algo'] = NULL;
        } else {
            unset($params['password']);
        }
        // Handle subscription plan changes.
        if (isset($params['plan_id'])) {
            $plan = Plan::find($params['plan_id']);
            if (!$plan) {
                return $this->fail([400202, __('Subscription plan does not exist')]);
            }
            $params['group_id'] = $plan->group_id;
        }
        // invite_user_email is an admin form helper, not a v2_user column.
        // Read its validated presence before removing it from the persistence
        // payload so omitted values preserve the current referral owner while
        // an explicit blank value still clears it.
        $hasInviteUserEmail = array_key_exists('invite_user_email', $params);
        $inviteUserEmail = $params['invite_user_email'] ?? null;
        unset($params['invite_user_email']);

        if ($hasInviteUserEmail) {
            if (blank($inviteUserEmail)) {
                $params['invite_user_id'] = null;
            } else {
                $inviteUser = User::byEmail($inviteUserEmail)->first();
                if (!$inviteUser) {
                    return $this->fail([400202, __('The referrer does not exist')]);
                }
                if ($this->referralWouldCreateCycle($user, $inviteUser)) {
                    return $this->fail([400203, __('The referral relationship would create a cycle')]);
                }
                $params['invite_user_id'] = $inviteUser->id;
            }
        }

        if (isset($params['banned']) && (int) $params['banned'] === 1) {
            $authService = new AuthService($user);
            $authService->removeAllSessions();
        }
        if (isset($params['balance'])) {
            $params['balance'] = $params['balance'] * 100;
        }
        if (isset($params['commission_balance'])) {
            $params['commission_balance'] = $params['commission_balance'] * 100;
        }

        $params = HookManager::filter('admin.user.update.params', $params, $request, $user);

        try {
            if (array_key_exists('invite_user_id', $params) && $params['invite_user_id'] !== null) {
                $updateResult = $this->updateWithReferralLocks($user, $params, $request);
            } else {
                // Clearing an edge cannot create a cycle, and unrelated user
                // edits should not hold graph locks or a longer transaction.
                HookManager::call('admin.user.update.before', [
                    'user' => $user,
                    'params' => $params,
                    'request' => $request,
                ]);
                $user->update($params);
                $updateResult = self::USER_UPDATE_SUCCEEDED;
            }
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, __('Failed to save user information')]);
        }

        if ($updateResult === self::USER_UPDATE_USER_MISSING) {
            return $this->fail([400202, __('The user does not exist')]);
        }
        if ($updateResult === self::USER_UPDATE_REFERRER_MISSING) {
            return $this->fail([400202, __('The referrer does not exist')]);
        }
        if ($updateResult === self::USER_UPDATE_REFERRAL_CYCLE) {
            return $this->fail([400203, __('The referral relationship would create a cycle')]);
        }

        HookManager::call('admin.user.update.after', [
            'user' => $user->refresh(),
            'params' => $params,
            'request' => $request,
        ]);

        return $this->success(true);
    }

    /**
     * Reject self-referrals, newly introduced ancestor cycles and an already
     * corrupt cyclic chain without relying on database-specific recursive SQL.
     */
    private function referralWouldCreateCycle(User $user, User $inviteUser): bool
    {
        $targetId = (int) $user->getKey();
        $visited = [];
        $current = $inviteUser;

        while ($current) {
            $currentId = (int) $current->getKey();
            if ($currentId === $targetId || isset($visited[$currentId])) {
                return true;
            }

            $visited[$currentId] = true;
            $nextId = (int) ($current->invite_user_id ?? 0);
            if ($nextId <= 0) {
                return false;
            }

            $current = User::query()
                ->select(['id', 'invite_user_id'])
                ->find($nextId);
        }

        return false;
    }

    /**
     * Serialize referral-owner changes with the smallest useful lock set.
     *
     * The target and its requested direct referrer are locked together in a
     * stable ID order. Each ancestor is then locked before its parent pointer
     * is read. Concurrent reciprocal (or longer) updates therefore either see
     * the committed edge or deadlock and retry the complete transaction.
     */
    private function updateWithReferralLocks(User $user, array $params, UserUpdate $request): string
    {
        return DB::transaction(function () use ($user, $params, $request): string {
            $targetId = (int) $user->getKey();
            $inviteUserId = filter_var(
                $params['invite_user_id'],
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            );
            if ($inviteUserId === false) {
                return self::USER_UPDATE_REFERRER_MISSING;
            }
            $inviteUserId = (int) $inviteUserId;

            $lockIds = [$targetId, $inviteUserId];
            $lockIds = array_values(array_unique($lockIds));
            sort($lockIds, SORT_NUMERIC);

            $lockedUsers = collect();
            foreach ($lockIds as $lockId) {
                $lockedRow = User::query()
                    ->whereKey($lockId)
                    ->lockForUpdate()
                    ->first();
                if ($lockedRow) {
                    $lockedUsers->put((int) $lockedRow->getKey(), $lockedRow);
                }
            }

            /** @var User|null $lockedUser */
            $lockedUser = $lockedUsers->get($targetId);
            if (!$lockedUser) {
                return self::USER_UPDATE_USER_MISSING;
            }

            /** @var User|null $lockedInviteUser */
            $lockedInviteUser = $lockedUsers->get($inviteUserId);
            if (!$lockedInviteUser) {
                return self::USER_UPDATE_REFERRER_MISSING;
            }

            if ($this->lockedReferralWouldCreateCycle($lockedUser, $lockedInviteUser, $lockedUsers)) {
                return self::USER_UPDATE_REFERRAL_CYCLE;
            }

            // Invoke the existing hook only after every referral row needed by
            // the invariant has been locked. Referral-lock deadlock retries
            // therefore happen before the hook, which still precedes update.
            HookManager::call('admin.user.update.before', [
                'user' => $user,
                'params' => $params,
                'request' => $request,
            ]);

            $lockedUser->update($params);

            return self::USER_UPDATE_SUCCEEDED;
        }, 3);
    }

    /**
     * Walk a referral chain while holding a row lock on every value read.
     */
    private function lockedReferralWouldCreateCycle(User $user, User $inviteUser, $lockedUsers): bool
    {
        $targetId = (int) $user->getKey();
        $visited = [];
        $current = $inviteUser;

        while ($current) {
            $currentId = (int) $current->getKey();
            if ($currentId === $targetId || isset($visited[$currentId])) {
                return true;
            }

            $visited[$currentId] = true;
            $nextId = (int) ($current->invite_user_id ?? 0);
            if ($nextId <= 0) {
                return false;
            }

            $current = $lockedUsers->get($nextId);
            if (!$current) {
                $current = User::query()
                    ->select(['id', 'invite_user_id'])
                    ->whereKey($nextId)
                    ->lockForUpdate()
                    ->first();
                if ($current) {
                    $lockedUsers->put($nextId, $current);
                }
            }
        }

        return false;
    }

    // Export users to CSV.
    public function dumpCSV(Request $request)
    {
        ini_set('memory_limit', '-1');
        gc_enable(); // Enable garbage collection.

        $scopeInfo = $this->resolveScope($request);
        $scope = $scopeInfo['scope'];
        $userIds = $scopeInfo['user_ids'];

        if ($scope === 'selected') {
            if (empty($userIds)) {
                return $this->fail([422, __('Selected user IDs cannot be empty')]);
            }
        }

        // Eager-load the plan relationship to avoid N+1 queries.
        $query = User::query()
            ->with('plan:id,name')
            ->orderBy('id', 'asc')
            ->select([
                'email',
                'balance',
                'commission_balance',
                'transfer_enable',
                'u',
                'd',
                'expired_at',
                'token',
                'plan_id'
            ]);

        if ($scope === 'selected') {
            $query->whereIn('id', $userIds);
        } elseif ($scope === 'filtered') {
            $this->applyFiltersAndSorts($request, $query);
        } // all: ignore filter/sort

        $filename = 'users_' . date('Y-m-d_His') . '.csv';

        return response()->streamDownload(function () use ($query) {
            // Open the output stream.
            $output = fopen('php://output', 'w');

            // Add a UTF-8 BOM so Excel displays every locale correctly.
            fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

            // Write localized CSV headers.
            fputcsv($output, [
                __('Email'),
                __('Balance'),
                __('Referral commission'),
                __('Total traffic'),
                __('Remaining traffic'),
                __('Subscription expires at'),
                __('Subscription plan'),
                __('Subscription Link')
            ]);

            // Process records in chunks to reduce memory use.
            $query->chunk(500, function ($users) use ($output) {
                foreach ($users as $user) {
                    try {
                        $row = [
                            $user->email,
                            number_format($user->balance / 100, 2),
                            number_format($user->commission_balance / 100, 2),
                            Helper::trafficConvert($user->transfer_enable),
                            Helper::trafficConvert($user->transfer_enable - ($user->u + $user->d)),
                            $user->expired_at ? date('Y-m-d H:i:s', $user->expired_at) : __('Never expires'),
                            $user->plan ? $user->plan->name : __('No subscription'),
                            Helper::getSubscribeUrl($user->token)
                        ];
                        fputcsv($output, $row);
                    } catch (\Exception $e) {
                        Log::error('CSV export failed', [
                            'user_id' => $user->id,
                            'email' => $user->email
                        ]);
                        continue; // Continue with the next record.
                    }
                }

                // Release cyclic references between chunks.
                gc_collect_cycles();
            });

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"'
        ]);
    }

    public function generate(UserGenerate $request)
    {
        if ($request->input('email_prefix')) {
            // If generate_count is specified with email_prefix, generate multiple users with incremented emails
            if ($request->input('generate_count')) {
                return $this->multiGenerateWithPrefix($request);
            }
            
            // Single user generation with email_prefix
            $email = $request->input('email_prefix') . '@' . $request->input('email_suffix');

            if (User::byEmail($email)->exists()) {
                return $this->fail([400201, __('Email already exists')]);
            }

            $userService = app(UserService::class);
            $user = $userService->createUser([
                'email' => $email,
                'password' => $request->input('password') ?? $email,
                'plan_id' => $request->input('plan_id'),
                'expired_at' => $request->input('expired_at'),
            ]);

            if (!$user->save()) {
                return $this->fail([500, __('User generation failed')]);
            }
            return $this->success(true);
        }

        if ($request->input('generate_count')) {
            return $this->multiGenerate($request);
        }
    }

    private function multiGenerate(Request $request)
    {
        $userService = app(UserService::class);
        $usersData = [];

        for ($i = 0; $i < $request->input('generate_count'); $i++) {
            $email = Helper::randomChar(6) . '@' . $request->input('email_suffix');
            $usersData[] = [
                'email' => $email,
                'password' => $request->input('password') ?? $email,
                'plan_id' => $request->input('plan_id'),
                'expired_at' => $request->input('expired_at'),
            ];
        }



        try {
            DB::beginTransaction();
            $users = [];
            foreach ($usersData as $userData) {
                $user = $userService->createUser($userData);
                $user->save();
                $users[] = $user;
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->fail([500, __('User generation failed')]);
        }

        // Export CSV when requested.
        if ($request->input('download_csv')) {
            $headers = [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => 'attachment; filename="users.csv"',
            ];
            $callback = function () use ($users, $request) {
                $handle = fopen('php://output', 'w');
                fputcsv($handle, [__('Account'), __('Password'), __('Expires at'), 'UUID', __('Created at'), __('Subscription Link')]);
                foreach ($users as $user) {
                    $user = $user->refresh();
                    $expireDate = $user['expired_at'] === NULL ? __('Never expires') : date('Y-m-d H:i:s', $user['expired_at']);
                    $createDate = date('Y-m-d H:i:s', $user['created_at']);
                    $password = $request->input('password') ?? $user['email'];
                    $subscribeUrl = Helper::getSubscribeUrl($user['token']);
                    fputcsv($handle, [$user['email'], $password, $expireDate, $user['uuid'], $createDate, $subscribeUrl]);
                }
                fclose($handle);
            };
            return response()->streamDownload($callback, 'users.csv', $headers);
        }

        // Return JSON by default.
        $data = collect($users)->map(function ($user) use ($request) {
            return [
                'email' => $user['email'],
                'password' => $request->input('password') ?? $user['email'],
                'expired_at' => $user['expired_at'] === NULL ? __('Never expires') : date('Y-m-d H:i:s', $user['expired_at']),
                'uuid' => $user['uuid'],
                'created_at' => date('Y-m-d H:i:s', $user['created_at']),
                'subscribe_url' => Helper::getSubscribeUrl($user['token']),
            ];
        });
        return response()->json([
            'code' => 0,
            'message' => __('Batch user generation succeeded'),
            'data' => $data,
        ]);
    }

    private function multiGenerateWithPrefix(Request $request)
    {
        $userService = app(UserService::class);
        $usersData = [];
        $emailPrefix = $request->input('email_prefix');
        $emailSuffix = $request->input('email_suffix');
        $generateCount = $request->input('generate_count');

        // Check if any of the emails with prefix already exist
        for ($i = 1; $i <= $generateCount; $i++) {
            $email = $emailPrefix . '_' . $i . '@' . $emailSuffix;
            if (User::where('email', $email)->exists()) {
                return $this->fail([400201, __('Email :email already exists', ['email' => $email])]);
            }
        }

        // Generate user data for batch creation
        for ($i = 1; $i <= $generateCount; $i++) {
            $email = $emailPrefix . '_' . $i . '@' . $emailSuffix;
            $usersData[] = [
                'email' => $email,
                'password' => $request->input('password') ?? $email,
                'plan_id' => $request->input('plan_id'),
                'expired_at' => $request->input('expired_at'),
            ];
        }

        try {
            DB::beginTransaction();
            $users = [];
            foreach ($usersData as $userData) {
                $user = $userService->createUser($userData);
                $user->save();
                $users[] = $user;
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->fail([500, __('User generation failed')]);
        }

        // Export CSV when requested.
        if ($request->input('download_csv')) {
            $headers = [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => 'attachment; filename="users.csv"',
            ];
            $callback = function () use ($users, $request) {
                $handle = fopen('php://output', 'w');
                fputcsv($handle, [__('Account'), __('Password'), __('Expires at'), 'UUID', __('Created at'), __('Subscription Link')]);
                foreach ($users as $user) {
                    $user = $user->refresh();
                    $expireDate = $user['expired_at'] === NULL ? __('Never expires') : date('Y-m-d H:i:s', $user['expired_at']);
                    $createDate = date('Y-m-d H:i:s', $user['created_at']);
                    $password = $request->input('password') ?? $user['email'];
                    $subscribeUrl = Helper::getSubscribeUrl($user['token']);
                    fputcsv($handle, [$user['email'], $password, $expireDate, $user['uuid'], $createDate, $subscribeUrl]);
                }
                fclose($handle);
            };
            return response()->streamDownload($callback, 'users.csv', $headers);
        }

        // Return JSON by default.
        $data = collect($users)->map(function ($user) use ($request) {
            return [
                'email' => $user['email'],
                'password' => $request->input('password') ?? $user['email'],
                'expired_at' => $user['expired_at'] === NULL ? __('Never expires') : date('Y-m-d H:i:s', $user['expired_at']),
                'uuid' => $user['uuid'],
                'created_at' => date('Y-m-d H:i:s', $user['created_at']),
                'subscribe_url' => Helper::getSubscribeUrl($user['token']),
            ];
        });
        return response()->json([
            'code' => 0,
            'message' => __('Batch user generation succeeded'),
            'data' => $data,
        ]);
    }

    public function sendMail(UserSendMail $request)
    {
        ini_set('memory_limit', '-1');
        $scopeInfo = $this->resolveScope($request);
        $scope = $scopeInfo['scope'];
        $userIds = $scopeInfo['user_ids'];

        if ($scope === 'selected') {
            if (empty($userIds)) {
                return $this->fail([422, __('Selected user IDs cannot be empty')]);
            }
        }

        $sortType = in_array($request->input('sort_type'), ['ASC', 'DESC']) ? $request->input('sort_type') : 'DESC';
        $sort = $request->input('sort') ? $request->input('sort') : 'created_at';

        $builder = User::query()
            ->with('plan:id,name')
            ->orderBy('id', 'desc');

        if ($scope === 'filtered') {
            // filtered: apply filters/sort
            $builder->orderBy($sort, $sortType);
            $this->applyFiltersAndSorts($request, $builder);
        } elseif ($scope === 'selected') {
            $builder->whereIn('id', $userIds);
        } // all: ignore filter/sort

        $subject = $request->input('subject');
        $content = $request->input('content');
        $appName = admin_setting('app_name', 'XBoard');
        $appUrl = admin_setting('app_url');

        $chunkSize = 1000;

        $builder->chunk($chunkSize, function ($users) use ($subject, $content, $appName, $appUrl) {
            foreach ($users as $user) {
                $vars = [
                    'app.name' => $appName,
                    'app.url' => $appUrl,
                    'now' => now()->format('Y-m-d H:i:s'),
                    'user.id' => $user->id,
                    'user.email' => $user->email,
                    'user.uuid' => $user->uuid,
                    'user.plan_name' => $user->plan?->name ?? '',
                    'user.expired_at' => $user->expired_at ? date('Y-m-d H:i:s', $user->expired_at) : '',
                    'user.transfer_enable' => (int) ($user->transfer_enable ?? 0),
                    'user.transfer_used' => (int) (($user->u ?? 0) + ($user->d ?? 0)),
                    'user.transfer_left' => (int) (($user->transfer_enable ?? 0) - (($user->u ?? 0) + ($user->d ?? 0))),
                ];

                $templateValue = [
                    'name' => $appName,
                    'url' => $appUrl,
                    'content' => $content,
                    'vars' => $vars,
                    'content_mode' => 'text',
                ];

                dispatch(new SendEmailJob([
                    'email' => $user->email,
                    'subject' => $subject,
                    'template_name' => 'notify',
                    'template_value' => $templateValue
                ], 'send_email_mass'));
            }
        });

        return $this->success(true);
    }

    public function ban(Request $request)
    {
        $scopeInfo = $this->resolveScope($request);
        $scope = $scopeInfo['scope'];
        $userIds = $scopeInfo['user_ids'];

        if ($scope === 'selected') {
            if (empty($userIds)) {
                return $this->fail([422, __('Selected user IDs cannot be empty')]);
            }
        }

        $sortType = in_array($request->input('sort_type'), ['ASC', 'DESC']) ? $request->input('sort_type') : 'DESC';
        $sort = $request->input('sort') ? $request->input('sort') : 'created_at';

        $builder = User::query()->orderBy('id', 'desc');

        if ($scope === 'filtered') {
            // filtered: keep current semantics
            $builder->orderBy($sort, $sortType);
            $this->applyFiltersAndSorts($request, $builder);
        } elseif ($scope === 'selected') {
            $builder->whereIn('id', $userIds);
        } // all: ignore filter/sort

        try {
            $builder->update([
                'banned' => 1
            ]);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, __('User processing failed')]);
        }
        // Full refresh not implemented.
        return $this->success(true);
    }

    // Delete user and related data.
    public function destroy(Request $request)
    {
        $request->validate([
            'id' => 'required|exists:App\Models\User,id'
        ], [
            'id.required' => __('User ID is required'),
            'id.exists' => __('The user does not exist')
        ]);
        $user = User::find($request->input('id'));
        HookManager::call('admin.user.destroy.before', [
            'user' => $user,
            'request' => $request,
        ]);

        try {
            DB::beginTransaction();
            $user->orders()->delete();
            $user->codes()->delete();
            $user->stat()->delete();
            $user->tickets()->delete();
            $user->delete();
            DB::commit();

            HookManager::call('admin.user.destroy.after', [
                'user' => $user,
                'request' => $request,
            ]);

            return $this->success(true);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e);
            return $this->fail([500, __('User deletion failed')]);
        }
    }
}
