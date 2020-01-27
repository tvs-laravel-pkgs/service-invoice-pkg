<?php

namespace Abs\ServiceInvoicePkg;

use Illuminate\Support\ServiceProvider;

class ServiceInvoicePkgServiceProvider extends ServiceProvider {
	/**
	 * Register services.
	 *
	 * @return void
	 */
	public function register() {
		$this->loadRoutesFrom(__DIR__ . '/routes/web.php');
		$this->loadRoutesFrom(__DIR__ . '/routes/api.php');
		$this->loadMigrationsFrom(__DIR__ . '/migrations');
		$this->loadViewsFrom(__DIR__ . '/views', 'service-invoice-pkg');
		$this->publishes([
			__DIR__ . '/public' => base_path('public'),
			__DIR__ . '/database/seeds/client' => 'database/seeds',
			__DIR__ . '/config/config.php' => config_path('service-invoice-pkg.php'),
		]);
	}

	/**
	 * Bootstrap services.
	 *
	 * @return void
	 */
	public function boot() {
		// $this->app->make('Abs\Basic\API\AuthController');
	}
}
