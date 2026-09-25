<?php

/**
 * Dough & Sauce module configuration.
 *
 * Why a config file and not a settings table — unlike the cleaning module?
 *
 * In cleaning, the SERVER computes the score with those weights, so making them
 * runtime-editable bought something real. Here the FRONTEND computes: the server
 * only ships these numbers out through /dough-sauce/bootstrap. They are values
 * that travel, not values that are used — with one exception, `buffer_max_pct`,
 * which is a genuine server-side guard.
 *
 * Changing a weight is a once-a-year event, so a deploy is an acceptable price
 * for one less table, one less model and two less endpoints. What we keep either
 * way is the thing that mattered: a SINGLE source for the weights. A second
 * client (a mobile app, a batch report) reads the same /bootstrap and cannot
 * drift from a number hardcoded in a browser bundle.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | The three tracked ingredients
    |--------------------------------------------------------------------------
    |
    | The truth about these lives in LC_PIZZA_DATA (ds_ingredients). This is a
    | copy, used to (a) validate the ingredient_key we are asked to store and
    | (b) hand the frontend the names/divisors without a second network call.
    |
    | `divisor` converts a sales quantity into the unit the inventory system
    | actually counts:  pizzas / 1 = balls, bread / 12 = balls, sauce / 80 = tubs.
    |
    | `inventory_ref` is the item's ultimatrix_id in the inventory project. We
    | were told these three codes are temporary and will be corrected — that is
    | one column in one file, so it does not block anything.
    |
    */
    'ingredients' => [

        'dough_18oz' => [
            'name'          => '18 OZ Dough ball',
            'unit'          => 'ball',
            'divisor'       => 1,
            'inventory_ref' => '0000',
            'sort_order'    => 1,
        ],

        'dough_10oz' => [
            'name'          => '10 OZ Dough balls',
            'unit'          => 'ball',
            'divisor'       => 12,
            'inventory_ref' => '00001',
            'sort_order'    => 2,
        ],

        'sauce' => [
            'name'          => 'Sauce containers',
            'unit'          => 'container',
            'divisor'       => 80,
            'inventory_ref' => '00002',
            'sort_order'    => 3,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Scoring weights — shipped to the frontend, never used here
    |--------------------------------------------------------------------------
    |
    |   score = variance_share x (cells_ok / denominator)
    |         + stickers_share x (stickers_compliance = yes)
    |         + quality_share  x (dough_quality       = pass)
    |
    | `variance_denominator`:
    |   'cells' -> the real cell count (7 days x 3 ingredients = 21)
    |   'fixed' -> `variance_denominator_fixed`, which reproduces the Excel
    |              workbook's 20 exactly. Kept so a disputed number can be
    |              checked against the sheet everyone still trusts.
    |
    */
    'scoring' => [
        'variance_share'             => 0.60,
        'stickers_share'             => 0.20,
        'quality_share'              => 0.20,
        'variance_denominator'       => 'cells',
        'variance_denominator_fixed' => 20,
        'progress_lookback_weeks'    => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Parameters the frontend forwards to LC_PIZZA_DATA
    |--------------------------------------------------------------------------
    |
    | `lookback_days` = how many same-weekday values to average (the previous
    | four Fridays for a Friday plan).
    |
    | `include_refunded` = false subtracts refunds. A refunded order is not
    | recurring demand, and the plan forecasts demand.
    |
    */
    'data_project' => [
        'lookback_days'    => 4,
        'include_refunded' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Default buffers
    |--------------------------------------------------------------------------
    |
    | Only used for a store that has never confirmed a plan. Every other time,
    | the default comes from that store's most recent confirmed plan — "the
    | buffer you last actually used" is a better suggestion than "the buffer
    | somebody set once and forgot".
    |
    */
    'default_buffers' => [
        'dough_18oz' => 10,
        'dough_10oz' => 10,
        'sauce'      => 50,
    ],

    /*
    |--------------------------------------------------------------------------
    | The one limit the server enforces
    |--------------------------------------------------------------------------
    |
    | A store manager owns his buffer, and a wrong one is handled by people, not
    | by this system. But 500% is not a decision, it is a typo, and a plan built
    | on it would be thrown away rather than questioned.
    |
    */
    'buffer_max_pct' => env('DS_BUFFER_MAX_PCT', 100),

    /*
    |--------------------------------------------------------------------------
    | Role names (verified against pizzasys by the auth middleware)
    |--------------------------------------------------------------------------
    |
    | Same pattern as config/cleaning.php. If pizzasys spells these differently,
    | change them here — no code refers to the literal strings.
    |
    */
    'manager_role'    => env('DS_MANAGER_ROLE', 'Store Manager'),
    'specialist_role' => env('DS_SPECIALIST_ROLE', 'Dough Specialist'),
];
