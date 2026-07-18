<?php

namespace Tests\Feature;

use App\Models\EnterpriseQuote;
use App\Models\QuoteMessage;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicPricingPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    public function test_pricing_page_renders_plans_and_the_calculator(): void
    {
        $this->get('/pricing')
            ->assertOk()
            ->assertSee('100 Machines')
            ->assertSee('5000 Machines')
            ->assertSee('Most popular')       // recommended badge
            ->assertSee('Size your plan')     // calculator
            ->assertSee('Request a quote');   // enterprise form
    }

    public function test_enterprise_quote_can_be_submitted(): void
    {
        $payload = [
            'company_name'    => 'Globex Corp',
            'contact_name'    => 'Jane Roe',
            'email'           => 'jane@globex.test',
            'phone'           => '+1 555 0100',
            'country'         => 'United States',
            'device_count'    => 12000,
            'current_rmm'     => 'NinjaOne',
            'expected_growth' => 'Doubling this year',
            'notes'           => 'Need SSO and a custom SLA.',
        ];

        $this->post('/quote', $payload)
            ->assertRedirect()
            ->assertSessionHas('quote_ok', true);

        $this->assertDatabaseHas('enterprise_quotes', [
            'company_name' => 'Globex Corp',
            'email'        => 'jane@globex.test',
            'device_count' => 12000,
            'status'       => 'new',
        ]);

        // A system message seeds the internal thread.
        $quote = EnterpriseQuote::first();
        $this->assertTrue(QuoteMessage::where('enterprise_quote_id', $quote->id)->where('author', 'system')->exists());
    }

    public function test_quote_submission_validates_required_fields(): void
    {
        $this->post('/quote', ['company_name' => 'X'])
            ->assertSessionHasErrors(['contact_name', 'email', 'device_count']);

        $this->assertDatabaseCount('enterprise_quotes', 0);
    }
}
