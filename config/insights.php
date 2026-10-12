<?php

return [
    /*
     * The farm the farm-plan MCP server answers for. A server is started for
     * one farm, so no tool can be talked into reading another.
     */
    'mcp_farm' => env('INSIGHTS_MCP_FARM'),
];
