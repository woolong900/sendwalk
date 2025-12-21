<?php

namespace App\Providers;

use App\Models\MailingList;
use App\Models\Campaign;
use App\Models\Automation;
use App\Policies\MailingListPolicy;
use App\Policies\CampaignPolicy;
use App\Policies\AutomationPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        MailingList::class => MailingListPolicy::class,
        Campaign::class => CampaignPolicy::class,
        Automation::class => AutomationPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        //
    }
}

