@php
    $tick = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>';
    $plans = [
        ['Starter', '$2', 'per endpoint / mo', 'For small MSPs getting started.', false,
            ['Up to 250 endpoints', 'Silent deployment, all package types', 'Desired-state software policies', 'Browser policies', 'Email & webhook alerts', 'Community support']],
        ['Growth', '$1.50', 'per endpoint / mo', 'For growing MSPs managing multiple clients.', true,
            ['Up to 2,500 endpoints', 'Everything in Starter', 'Deployment rings & maintenance windows', 'Compliance reporting & CSV export', 'REST API & integrations', 'Priority support']],
        ['Enterprise', 'Custom', 'volume pricing', 'For large fleets and bespoke needs.', false,
            ['Unlimited endpoints', 'Everything in Growth', 'Dedicated database / instance', 'SSO & custom roles', 'Onboarding & SLA', 'Named account manager']],
    ];
@endphp
<div class="plans">
    @foreach ($plans as [$name,$price,$unit,$desc,$featured,$items])
        <div class="plan {{ $featured ? 'featured' : '' }}">
            <div class="pname">{{ $name }}</div>
            <div class="price">{{ $price }} <span>{{ $unit }}</span></div>
            <p class="pdesc">{{ $desc }}</p>
            <ul>
                @foreach ($items as $item)
                    <li>{!! $tick !!} <span>{{ $item }}</span></li>
                @endforeach
            </ul>
            <a href="{{ route('get-started') }}" class="btn {{ $featured ? 'btn-primary' : 'btn-ghost' }}" style="justify-content:center;">
                {{ $name === 'Enterprise' ? 'Contact sales' : 'Get started' }}
            </a>
        </div>
    @endforeach
</div>
