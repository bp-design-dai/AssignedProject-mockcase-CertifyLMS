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
use App\Validation\EmailValidator;
use App\View\Composers\AiChatWidgetComposer;
use App\View\Composers\EnrollmentSwitcherComposer;
use App\View\Composers\NotificationBadgeComposer;
use App\View\Composers\SectionPageMetaComposer;
use App\View\Composers\SidebarBadgeComposer;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(EnrollmentStatsService::class);
        $this->app->bind(StripeCheckoutGateway::class, StripeApiCheckoutGateway::class);
        $this->app->bind(GeminiGateway::class, HttpGeminiGateway::class);
        $this->app->bind(ClientInterface::class, fn () => new Client(['connect_timeout' => 2, 'timeout' => 5]));
        $this->app->bind(GoogleCalendarGateway::class, fn () => new GoogleApiCalendarGateway($this->app->make(ClientInterface::class)));
    }

    public function boot(): void
    {
        Validator::resolver(fn ($translator, $data, $rules, $messages, $attributes) => new EmailValidator($translator, $data, $rules, $messages, $attributes));

        View::composer('layouts._partials.sidebar-*', SidebarBadgeComposer::class);
        View::composer('layouts._partials.topbar', NotificationBadgeComposer::class);
        View::composer('components.enrollment-switcher', EnrollmentSwitcherComposer::class);
        View::composer('learning.sections.show', SectionPageMetaComposer::class);
        View::composer('layouts.app', AiChatWidgetComposer::class);
    }
}
