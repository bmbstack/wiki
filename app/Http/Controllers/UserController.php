<?php

namespace BookStack\Http\Controllers;

use BookStack\Activity;
use Illuminate\Http\Request;

use Illuminate\Http\Response;
use BookStack\Http\Requests;
use BookStack\Repos\UserRepo;
use BookStack\Services\SocialAuthService;
use BookStack\User;

class UserController extends Controller
{

    protected $user;
    protected $userRepo;

    /**
     * UserController constructor.
     * @param User     $user
     * @param UserRepo $userRepo
     */
    public function __construct(User $user, UserRepo $userRepo)
    {
        $this->user = $user;
        $this->userRepo = $userRepo;
        parent::__construct();
    }

    /**
     * Display a listing of the users.
     * @return Response
     */
    public function index()
    {
        $this->checkPermission('users-manage');
        $users = $this->userRepo->getAllUsers();
        $this->setPageTitle('Users');
        return view('users/index', ['users' => $users]);
    }

    /**
     * Show the form for creating a new user.
     * @return Response
     */
    public function create()
    {
        $this->checkPermission('users-manage');
        $authMethod = config('auth.method');
        return view('users/create', ['authMethod' => $authMethod]);
    }

    /**
     * Store a newly created user in storage.
     * @param  Request $request
     * @return Response
     */
    public function store(Request $request)
    {
        $this->checkPermission('users-manage');
        $validationRules = [
            'name'             => 'required',
            'email'            => 'required|email|unique:users,email'
        ];

        $authMethod = config('auth.method');
        if ($authMethod === 'standard') {
            $validationRules['password'] = 'required|min:5';
            $validationRules['password-confirm'] = 'required|same:password';
        } elseif ($authMethod === 'ldap') {
            $validationRules['external_auth_id'] = 'required';
        }
        $this->validate($request, $validationRules);


        $user = $this->user->fill($request->all());

        if ($authMethod === 'standard') {
            $user->password = bcrypt($request->get('password'));
        } elseif ($authMethod === 'ldap') {
            $user->external_auth_id = $request->get('external_auth_id');
        }

        $user->save();

        if ($request->has('roles')) {
            $roles = $request->get('roles');
            $user->roles()->sync($roles);
        }

        // Get avatar from gravatar and save
        if (!config('services.disable_services')) {
            $avatar = \Images::saveUserGravatar($user);
            $user->avatar()->associate($avatar);
            $user->save();
        }

        return redirect('/settings/users');
    }

    /**
     * Show the form for editing the specified user.
     * @param  int              $id
     * @param SocialAuthService $socialAuthService
     * @return Response
     */
    public function edit($id, SocialAuthService $socialAuthService)
    {
        $this->checkPermissionOr('users-manage', function () use ($id) {
            return $this->currentUser->id == $id;
        });

        $authMethod = config('auth.method');

        $user = $this->user->findOrFail($id);
        $activeSocialDrivers = $socialAuthService->getActiveDrivers();
        $this->setPageTitle('User Profile');
        return view('users/edit', ['user' => $user, 'activeSocialDrivers' => $activeSocialDrivers, 'authMethod' => $authMethod]);
    }

    /**
     * Update the specified user in storage.
     * @param  Request $request
     * @param  int     $id
     * @return Response
     */
    public function update(Request $request, $id)
    {
        $this->preventAccessForDemoUsers();
        $this->checkPermissionOr('users-manage', function () use ($id) {
            return $this->currentUser->id == $id;
        });

        $this->validate($request, [
            'name'             => 'min:2',
            'email'            => 'min:2|email|unique:users,email,' . $id,
            'password'         => 'min:5|required_with:password_confirm',
            'password-confirm' => 'same:password|required_with:password'
        ], [
            'password-confirm.required_with' => 'Password confirmation required'
        ]);

        $user = $this->user->findOrFail($id);
        $user->fill($request->all());

        // Role updates
        if (userCan('users-manage') && $request->has('roles')) {
            $roles = $request->get('roles');
            $user->roles()->sync($roles);
        }

        // Password updates
        if ($request->has('password') && $request->get('password') != '') {
            $password = $request->get('password');
            $user->password = bcrypt($password);
        }

        // External auth id updates
        if ($this->currentUser->can('users-manage') && $request->has('external_auth_id')) {
            $user->external_auth_id = $request->get('external_auth_id');
        }

        $user->save();
        session()->flash('success', 'User successfully updated');

        $redirectUrl = userCan('users-manage') ? '/settings/users' : '/settings/users/' . $user->id;
        return redirect($redirectUrl);
    }

    /**
     * Show the user delete page.
     * @param $id
     * @return \Illuminate\View\View
     */
    public function delete($id)
    {
        $this->checkPermissionOr('users-manage', function () use ($id) {
            return $this->currentUser->id == $id;
        });

        $user = $this->user->findOrFail($id);
        $this->setPageTitle('Delete User ' . $user->name);
        return view('users/delete', ['user' => $user]);
    }

    /**
     * Remove the specified user from storage.
     * @param  int $id
     * @return Response
     */
    public function destroy($id)
    {
        $this->preventAccessForDemoUsers();
        $this->checkPermissionOr('users-manage', function () use ($id) {
            return $this->currentUser->id == $id;
        });

        $user = $this->userRepo->getById($id);
        if ($this->userRepo->isOnlyAdmin($user)) {
            session()->flash('error', 'You cannot delete the only admin');
            return redirect($user->getEditUrl());
        }
        $this->userRepo->destroy($user);

        return redirect('/settings/users');
    }

    /**
     * Show the user profile page
     * @param $id
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function showProfilePage($id)
    {
        $user = $this->userRepo->getById($id);
        $userActivity = $this->userRepo->getActivity($user);
        $recentlyCreated = $this->userRepo->getRecentlyCreated($user, 5, 0);
        $assetCounts = $this->userRepo->getAssetCounts($user);
        return view('users/profile', [
            'user' => $user,
            'activity' => $userActivity,
            'recentlyCreated' => $recentlyCreated,
            'assetCounts' => $assetCounts
        ]);
    }
}