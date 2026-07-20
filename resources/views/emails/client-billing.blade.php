<x-mail::message>
@if ($stage === 'failed')
# Your payment didn't go through

Hi {{ $client->company_name }},

We tried to charge your card for this month's PioDeploy subscription
({{ $client->subscription_machines ? number_format($client->subscription_machines).' machines' : 'your plan' }}@if ($client->subscription_cents), ${{ number_format($client->subscription_cents / 100, 2) }}/month@endif) and the payment was declined.

**Nothing is broken yet** — your fleet keeps working, and our payment
provider will retry automatically. To settle it right away, update your
card here:

<x-mail::button :url="$billingUrl">Fix payment</x-mail::button>
@elseif ($stage === 'reminder')
# Payment still due

Hi {{ $client->company_name }},

Your PioDeploy subscription payment is still outstanding. Your fleet
keeps working for now, but your account will be **suspended in {{ $daysLeft }} day{{ $daysLeft === 1 ? '' : 's' }}**
unless the payment goes through.

<x-mail::button :url="$billingUrl">Update card &amp; pay now</x-mail::button>
@elseif ($stage === 'suspended')
# Account suspended

Hi {{ $client->company_name }},

We could not collect payment for your PioDeploy subscription, so your
account has been suspended. Your data and machine history are safe, and
paying reactivates everything automatically — no need to contact us.

<x-mail::button :url="$billingUrl">Pay &amp; reactivate</x-mail::button>
@elseif ($stage === 'restored')
# Welcome back

Hi {{ $client->company_name }},

Your payment came through and your PioDeploy account is fully active
again. Thanks for sorting it out — nothing else is needed from you.

<x-mail::button :url="$billingUrl">View your billing</x-mail::button>
@endif

Questions about the charge? Just reply to this email.

{{ config('app.name') }}
</x-mail::message>
