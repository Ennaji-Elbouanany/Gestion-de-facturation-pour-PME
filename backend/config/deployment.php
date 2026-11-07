<?php

return [
    'release_sha' => env('RENDER_GIT_COMMIT', is_file(base_path('RELEASE_SHA')) ? trim(file_get_contents(base_path('RELEASE_SHA'))) : 'development'),
];
