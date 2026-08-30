<?php

namespace App\Http\Middleware;

use App\Services\Plugin\HookManager;
use App\Services\Plugin\PluginManager;
use Closure;
use Illuminate\Http\Request;

/**
 * Middleware to initialize all enabled plugins at the beginning of a request.
 * It ensures that all plugin hooks, routes, and services are ready.
 */
class InitializePlugins
{
    protected PluginManager $pluginManager;

    public function __construct(PluginManager $pluginManager)
    {
        $this->pluginManager = $pluginManager;
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // Hook storage lives in the long-running application container. Clear
        // the previous Octane request before booting the plugins enabled for
        // this request; otherwise callbacks accumulate and a disabled plugin
        // can remain active until the worker restarts.
        HookManager::reset();
        $this->pluginManager->prepareForRequest();
        $this->pluginManager->initializeEnabledPlugins();

        return $next($request);
    }
}
