<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\StripeClient;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

class BillingController extends Controller
{
    private StripeClient $stripe;

    public function __construct()
    {
        $this->stripe = new StripeClient(config('services.stripe.secret'));
    }

    public function plans(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => Plan::all()]);
    }

    public function checkout(Request $request): JsonResponse
    {
        $data = $request->validate(['price_id' => ['required', 'string']]);
        $user = $request->user();

        // Ensure Stripe customer exists
        if (! $user->stripe_customer_id) {
            $customer = $this->stripe->customers->create(['email' => $user->email, 'name' => $user->name]);
            $user->update(['stripe_customer_id' => $customer->id]);
        }

        $session = $this->stripe->checkout->sessions->create([
            'customer'             => $user->stripe_customer_id,
            'mode'                 => 'subscription',
            'payment_method_types' => ['card'],
            'line_items'           => [['price' => $data['price_id'], 'quantity' => 1]],
            'success_url'          => config('app.frontend_url') . '/account?checkout=success',
            'cancel_url'           => config('app.frontend_url') . '/pricing',
            'metadata'             => ['user_id' => $user->id],
        ]);

        return response()->json(['success' => true, 'data' => ['url' => $session->url]]);
    }

    public function portal(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->stripe_customer_id) {
            return response()->json(['success' => false, 'data' => null, 'error' => ['code' => 'NO_BILLING', 'message' => 'No billing account found.']], 404);
        }

        $session = $this->stripe->billingPortal->sessions->create([
            'customer'   => $user->stripe_customer_id,
            'return_url' => config('app.frontend_url') . '/account',
        ]);

        return response()->json(['success' => true, 'data' => ['url' => $session->url]]);
    }

    public function webhook(Request $request): JsonResponse
    {
        $payload   = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $secret    = config('services.stripe.webhook_secret');

        try {
            $event = Webhook::constructEvent($payload, $sigHeader, $secret);
        } catch (SignatureVerificationException $e) {
            Log::warning('Stripe webhook signature mismatch');
            return response()->json(['error' => 'Invalid signature'], 400);
        }

        match ($event->type) {
            'checkout.session.completed'       => $this->handleCheckoutCompleted($event->data->object),
            'invoice.paid'                     => $this->handleInvoicePaid($event->data->object),
            'invoice.payment_failed'           => $this->handlePaymentFailed($event->data->object),
            'customer.subscription.updated'    => $this->handleSubscriptionUpdated($event->data->object),
            'customer.subscription.deleted'    => $this->handleSubscriptionDeleted($event->data->object),
            default                            => null,
        };

        return response()->json(['received' => true]);
    }

    private function handleCheckoutCompleted(object $session): void
    {
        if ($session->mode !== 'subscription') return;
        $subscription = $this->stripe->subscriptions->retrieve($session->subscription);
        $this->syncSubscription($subscription);
    }

    private function handleInvoicePaid(object $invoice): void
    {
        if (! $invoice->subscription) return;
        $subscription = $this->stripe->subscriptions->retrieve($invoice->subscription);
        $this->syncSubscription($subscription);
    }

    private function handlePaymentFailed(object $invoice): void
    {
        Subscription::where('stripe_subscription_id', $invoice->subscription)
            ->update(['status' => 'past_due']);
    }

    private function handleSubscriptionUpdated(object $sub): void
    {
        $this->syncSubscription($sub);
    }

    private function handleSubscriptionDeleted(object $sub): void
    {
        Subscription::where('stripe_subscription_id', $sub->id)
            ->update(['status' => 'canceled', 'canceled_at' => now()]);

        $dbSub = Subscription::where('stripe_subscription_id', $sub->id)->first();
        if ($dbSub) {
            $freePlan = Plan::where('slug', 'free')->first();
            $dbSub->user->update(['plan_id' => $freePlan->id]);
        }
    }

    private function syncSubscription(object $stripeSub): void
    {
        $priceId = $stripeSub->items->data[0]->price->id ?? null;
        $plan    = Plan::where('stripe_price_id', $priceId)->first();
        if (! $plan) return;

        $customer = $this->stripe->customers->retrieve($stripeSub->customer);
        $user     = \App\Models\User::where('stripe_customer_id', $customer->id)->first();
        if (! $user) return;

        Subscription::updateOrCreate(
            ['stripe_subscription_id' => $stripeSub->id],
            [
                'user_id'             => $user->id,
                'plan_id'             => $plan->id,
                'status'              => $stripeSub->status,
                'current_period_end'  => \Carbon\Carbon::createFromTimestamp($stripeSub->current_period_end),
                'trial_ends_at'       => $stripeSub->trial_end ? \Carbon\Carbon::createFromTimestamp($stripeSub->trial_end) : null,
            ]
        );

        $user->update(['plan_id' => $plan->id]);
    }
}
