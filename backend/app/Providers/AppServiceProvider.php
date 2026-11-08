<?php

namespace App\Providers;

use App\Mail\Transport\ResendHttpTransport;
use App\Models\Client;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Quote;
use App\Models\User;
use App\Policies\ClientPolicy;
use App\Policies\CompanyPolicy;
use App\Policies\InvoicePolicy;
use App\Policies\PaymentPolicy;
use App\Policies\ProductPolicy;
use App\Policies\QuotePolicy;
use App\Policies\UserPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Resend uses HTTPS (443), not SMTP ports blocked by free hosting.
        Mail::extend('resend_http', fn (array $config) => new ResendHttpTransport(
            (string) config('services.resend.key', ''),
        ));

        Gate::policy(Client::class, ClientPolicy::class);
        Gate::policy(Product::class, ProductPolicy::class);
        Gate::policy(Invoice::class, InvoicePolicy::class);
        Gate::policy(Quote::class, QuotePolicy::class);
        Gate::policy(Payment::class, PaymentPolicy::class);
        Gate::policy(Company::class, CompanyPolicy::class);
        Gate::policy(User::class, UserPolicy::class);

        RateLimiter::for('account-security', fn (Request $request) => Limit::perMinute(5)->by('account-security:'.$request->user()->id));
        RateLimiter::for('account-verification', fn (Request $request) => Limit::perMinute(3)->by('verification:'.$request->user()->id));

        RateLimiter::for('login', function (Request $request) {
            $email = is_string($request->input('email')) ? strtolower(trim($request->input('email'))) : '';

            return Limit::perMinute(5)->by($email.'|'.$request->ip());
        });
    }
}
