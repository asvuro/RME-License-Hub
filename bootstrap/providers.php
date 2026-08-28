<?php

use App\Providers\AppServiceProvider;
use App\Providers\BroadcastServiceProvider;
use App\Providers\HubAuthServiceProvider;

return [
    AppServiceProvider::class,
    BroadcastServiceProvider::class,
    HubAuthServiceProvider::class,
];
