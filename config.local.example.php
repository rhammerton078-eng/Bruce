<?php
// Copy this file to config.local.php and keep only what you need to change.
// config.local.php is in .gitignore: it never goes to GitHub and is not overwritten by git pull.
return [
    'db' => [
        'port' => 3306,          // 3307 when ATLAS runs its own MySQL next to the school's (portable D: setup)
        // 'user' => 'atlas',
        // 'pass' => 'your-password',
    ],
    // 'ollama' => ['chat_model' => 'qwen2.5:3b'],   // smaller, faster model
];
