<?php

use App\Mcp\Servers\FarmPlanServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::local('farm-plan', FarmPlanServer::class);
