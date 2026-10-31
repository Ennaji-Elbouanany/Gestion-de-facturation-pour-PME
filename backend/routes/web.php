<?php

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/email/verify/{id}/{hash}', function (Request $request, int $id, string $hash) {
    $user = User::findOrFail($id);

    abort_unless(hash_equals(sha1($user->getEmailForVerification()), $hash), 403);

    if (!$user->hasVerifiedEmail()) {
        $user->markEmailAsVerified();
    }

    return redirect(rtrim((string) env('FRONTEND_URL', 'http://localhost:5173'), '/').'/login?verified=1');
})->middleware('signed')->name('verification.verify');
