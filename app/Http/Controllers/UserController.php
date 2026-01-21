<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class UserController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $users = User::query()
            ->with('userGroups')
            ->orderBy('id', 'desc')
            ->paginate(15);

        // Groups list comes from stores table (distinct group numbers)
        $groups = Store::query()
            ->select('group')
            ->distinct()
            ->whereNotNull('group')
            ->orderBy('group')
            ->pluck('group')
            ->values()
            ->toArray();

        return Inertia::render('Users/Index', [
            'users' => $users,
            'groups' => $groups,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    // public function create()
    // {
    //     $groups = Store::distinct()
    //         ->whereNotNull('group')
    //         ->pluck('group')
    //         ->sort()
    //         ->values()
    //         ->toArray();

    //     return Inertia::render('Users/Create', ['groups' => $groups]);
    // }




    /**
     * Store a newly created resource in storage.
     */
    // public function store(Request $request)
    // {
    //     $validated = $request->validate([
    //         'name' => ['required', 'string', 'max:255'],
    //         'email' => ['required', 'email', 'unique:users,email'],
    //         'role' => ['required', 'in:Admin,User'],
    //         'password' => ['required', 'string', 'min:8', 'confirmed'],
    //         'groups' => ['array', 'required_if:role,User'],
    //         'groups.*' => ['integer'],
    //     ]);

    //     $validated['password'] = Hash::make($validated['password']);
    //     $groups = $validated['groups'] ?? [];
    //     unset($validated['groups']);

    //     $user = User::create($validated);

    //     // Assign groups if user is User role
    //     if ($user->role === 'User' && !empty($groups)) {
    //         foreach ($groups as $group) {
    //             $user->userGroups()->create(['group' => $group]);
    //         }
    //     }

    //     return redirect()->route('users.index')->with('success', 'User created successfully.');
    // }

    /**
     * Show the form for editing the specified resource.
     */
    // public function edit(User $user)
    // {
    //     $groups = Store::distinct()
    //         ->whereNotNull('group')
    //         ->pluck('group')
    //         ->sort()
    //         ->values()
    //         ->toArray();

    //     $userGroups = $user->userGroups()
    //         ->pluck('group')
    //         ->toArray();

    //     return Inertia::render('Users/Edit', [
    //         'user' => $user,
    //         'groups' => $groups,
    //         'userGroups' => $userGroups,
    //     ]);
    // }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'role' => ['required', 'in:Admin,User'],
            'groups' => ['array'],
            'groups.*' => ['integer', 'min:1'],
        ]);

        $role = $validated['role'];
        $groups = $validated['groups'] ?? [];

        // Update ONLY role
        $user->update([
            'role' => $role,
        ]);

        // Sync groups based on role
        if ($role === 'Admin') {
            // Admins have access to everything -> no groups needed
            $user->userGroups()->delete();
        } else {
            // User role must have group assignments
            $user->userGroups()->delete();
            foreach ($groups as $group) {
                $user->userGroups()->create(['group' => $group]);
            }
        }

        return redirect()
            ->route('users.index')
            ->with('success', 'User permissions updated successfully.');
    }
    /**
     * Remove the specified resource from storage.
     */
    // public function destroy(User $user)
    // {
    //     $user->userGroups()->delete();
    //     $user->delete();
    //     return redirect()->route('users.index')->with('success', 'User deleted successfully.');
    // }
}
