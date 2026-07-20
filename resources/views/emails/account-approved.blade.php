@component('mail::message')
# Welcome to PioDeploy, {{ $signup->contact_name }}!

Your payment has been verified and your account for **{{ $signup->company_name }}**
({{ $signup->machines }} machines, {{ $signup->monthlyLabel() }}) is now active.

Sign in with the email address and password you chose during signup:

@component('mail::button', ['url' => $loginUrl])
Sign in to PioDeploy
@endcomponent

**Your first steps:**

1. **Add your team** — invite technicians from the Team page.
2. **Create a project** — a project groups the machines of one site or department.
3. **Enrol machines** — the Enrolment page generates a ready-to-run script (GPO, Intune, RMM or single machine); your fleet appears in the portal within minutes.

If anything is unclear, just reply to this email — a real person reads it.

Thanks,<br>
The PioDeploy team
@endcomponent
