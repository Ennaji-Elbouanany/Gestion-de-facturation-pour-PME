<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Policies\Concerns\ChecksCompany;
use Illuminate\Auth\Access\Response;

class PaymentPolicy
{
    use ChecksCompany;

    public function viewAny(User $user, ?Invoice $invoice = null): Response
    {
        return $this->permit($user, ['admin', 'comptable'], $invoice);
    }

    public function view(User $user, Payment $payment): Response
    {
        return $this->permit($user, ['admin', 'comptable'], $payment->invoice);
    }

    public function create(User $user, Invoice $invoice): Response
    {
        return $this->permit($user, ['admin', 'comptable'], $invoice);
    }

    public function update(User $user, Payment $payment): Response
    {
        return $this->view($user, $payment);
    }
}
