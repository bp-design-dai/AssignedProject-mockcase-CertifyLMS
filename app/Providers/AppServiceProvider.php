<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\GeminiGateway;
use App\Contracts\GoogleCalendarGateway;
use App\Contracts\StripeCheckoutGateway;
use App\Integrations\Gemini\HttpGeminiGateway;
use App\Integrations\GoogleCalendar\GoogleApiCalendarGateway;
use App\Integrations\Stripe\StripeApiCheckoutGateway;
use App\Services\EnrollmentStatsService;
use App\View\Composers\AiChatWidgetComposer;
use App\View\Composers\EnrollmentSwitcherComposer;
use App\View\Composers\NotificationBadgeComposer;
use App\View\Composers\SectionPageMetaComposer;
use App\View\Composers\SidebarBadgeComposer;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(EnrollmentStatsService::class);
        $this->app->bind(StripeCheckoutGateway::class, StripeApiCheckoutGateway::class);
        $this->app->bind(GeminiGateway::class, HttpGeminiGateway::class);
        $this->app->bind(GoogleCalendarGateway::class, fn () => new GoogleApiCalendarGateway(new Client(['connect_timeout' => 2, 'timeout' => 5])));
    }

    public function boot(): void
    {
        View::composer('layouts._partials.sidebar-*', SidebarBadgeComposer::class);
        View::composer('layouts._partials.topbar', NotificationBadgeComposer::class);
        View::composer('components.enrollment-switcher', EnrollmentSwitcherComposer::class);
        View::composer('learning.sections.show', SectionPageMetaComposer::class);
        View::composer('layouts.app', AiChatWidgetComposer::class);
    }
}
