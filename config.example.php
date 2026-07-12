<?php

declare(strict_types=1);

return [
    'github_username' => 'bchilton9',

    // Optional. Create a fine-grained read-only GitHub token if you want the
    // PHP site to display private repositories. Never commit config.php.
    'github_token' => '',

    // Cache GitHub API results to avoid rate-limit problems.
    'github_cache_seconds' => 900,

    // Set to false to hide archived repositories completely.
    'show_archived' => true,

    // Optional non-GitHub projects. Leave this as [] when none are needed.
    'custom_projects' => [
        [
            'name' => 'TrainTo.Zone',
            'link' => 'https://trainto.zone',
            'description' => 'A fantasy-themed guild and community hub.',
            'archived' => false,
        ],
        [
            'name' => 'The Gadget Guide',
            'link' => 'https://thegadget.guide',
            'description' => 'Mobile-friendly step-by-step technology guides.',
            'archived' => false,
        ],
    ],
];
