@extends('marketing.layout')
@section('title', 'Privacy policy — PioDeploy')
@section('meta', 'How ' . $company . ' collects, uses and protects data in the PioDeploy platform.')

@section('content')
<section class="page-hero">
    <div class="container">
        <span class="eyebrow">Legal</span>
        <h1>Privacy policy</h1>
        <p class="muted" style="margin:1rem auto 0;">Last updated {{ date('F Y') }}</p>
    </div>
</section>

<section class="section">
    <div class="container prose">
        <p>{{ $company }} ("we", "us") operates the PioDeploy platform ("the Service"). This policy
            explains what data the Service handles and how we protect it. It is provided as a template
            and should be reviewed by your legal counsel before publication.</p>

        <h2>1. Data we process</h2>
        <ul>
            <li><strong>Account data</strong> — names, email addresses and roles of your team members who sign in to the portal.</li>
            <li><strong>Fleet inventory</strong> — hardware and installed-software details reported by agents on your managed machines (hostname, OS, serial, CPU, memory, disk, installed applications).</li>
            <li><strong>Operational data</strong> — deployment jobs, policy definitions, compliance results and audit logs generated as you use the Service.</li>
            <li><strong>Contact submissions</strong> — information you provide through our contact and request-access forms.</li>
        </ul>

        <h2>2. What we do not collect</h2>
        <p>The agent inventories software and hardware for management purposes. It does not read documents,
            browsing history, keystrokes or personal files, and it does not capture screen contents.</p>

        <h2>3. How we use data</h2>
        <ul>
            <li>To provide the Service — deploying software, enforcing policies and reporting compliance.</li>
            <li>To secure accounts, prevent abuse and maintain an audit trail.</li>
            <li>To respond to your enquiries and support requests.</li>
        </ul>

        <h2>4. Security</h2>
        <p>API keys are stored hashed, never in plain text. Access is governed by role-based permissions
            and per-tenant isolation, and all sensitive actions are recorded in an audit log. Data in transit
            is protected with TLS. Self-hosted deployments run entirely within your own infrastructure.</p>

        <h2>5. Data sharing</h2>
        <p>We do not sell personal data. Data is shared only with sub-processors necessary to operate the
            Service (such as hosting and email delivery), or where required by law.</p>

        <h2>6. Retention</h2>
        <p>Operational and audit data are retained for the period configured in your instance settings and
            then pruned automatically. You may request deletion of your account data at any time.</p>

        <h2>7. Your rights</h2>
        <p>Depending on your jurisdiction you may have rights to access, correct, export or delete personal
            data we hold. Contact us to exercise them.</p>

        <h2>8. Contact</h2>
        <p>Questions about this policy? Reach us at
            <a href="mailto:{{ $email }}" style="color:var(--teal-700);font-weight:600;">{{ $email }}</a>
            or via our <a href="{{ route('contact') }}" style="color:var(--teal-700);font-weight:600;">contact page</a>.</p>
    </div>
</section>
@endsection
