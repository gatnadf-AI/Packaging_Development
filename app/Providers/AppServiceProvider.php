<?php

namespace App\Providers;

use App\Domain\Access\SessionInventory;
use App\Domain\Notify\WorkingDays;
use App\Listeners\RecordLoginMetadata;
use Illuminate\Auth\Events\Login;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One calendar read per request rather than one per deadline computed
        // (TSD §9). The object memoises its own lookup table, so a request that
        // derives fifty SLAs still asks MySQL once.
        $this->app->singleton(WorkingDays::class, fn (): WorkingDays => WorkingDays::fromConfig());

        // Stateless over the database, so a shared instance costs nothing and
        // keeps the queue-window rule in one place.
        $this->app->singleton(SessionInventory::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // TSD-1103 with FSD-105: the session row learns its owner and the
        // account learns when it was last used, at the one moment both are true.
        Event::listen(Login::class, RecordLoginMetadata::class);

        // Mass assignment violations are a hard error in every environment.
        // Silently dropping an attribute is how a new column stops being saved
        // and nobody notices for a month.
        Model::preventSilentlyDiscardingAttributes($this->app->isProduction() ? false : true);
    }
}
