<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;

class UserManagementController extends Controller
{
    public function index(): View
    {
        Gate::authorize('users.view');

        return view('user-management.index');
    }
}
