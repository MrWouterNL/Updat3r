<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class UserController extends Controller
{

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('profile');
    }

    /**
     * Display the specified user.
     *
     * @param User $user
     * @return View
     */
    public function show(User $user)
    {
        return view('profile.show', ['user' => $user]);
    }

    /**
     * Show the form for editing users.
     *
     * @param User $user
     * @return View
     */
    public function edit(User $user)
    {
        return view('profile.edit', ['user' => $user]);
    }

    /**
     * Update the specified user in storage.
     *
     * @param ProfileUpdateRequest $request
     * @param User $user
     * @return RedirectResponse
     */
    public function update(ProfileUpdateRequest $request, User $user)
    {
        $data = $request->validated();
        if (isset($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        if ($user->update($data)) {
            return redirect(route('users.show', ['user' => $user]))->with([
                'success' => 'Successfully updated your personal information'
            ]);
        } else {
            return redirect(route('users.edit', ['user' => $user]))->with([
                'error' => 'Something went wrong when attempting to change your personal information'
            ]);
        }
    }

    /**
     * Remove the specified user from storage.
     *
     * @param User $user
     * @return RedirectResponse
     */
    public function destroy(User $user)
    {
        $projectNames = $user->projects->pluck('name')->toArray();
        if ($user->delete()) {
            foreach ($projectNames as $projectName) {
                Storage::deleteDirectory('updates/' . $projectName);
            }
            return redirect(route('login'))->with('success', 'Your account has been deleted.');
        }
        return redirect(route('profile.show', ['user' => $user]))
            ->with('error', 'Something went wrong when deleting your account. Please let us know if this problem persists.');
    }
}
