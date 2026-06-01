<?php
require_once __DIR__ . '/config.php';

function get_available_skills(): array {
    $ch = curl_init('https://api.anthropic.com/v1/skills?source=custom&limit=100');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'x-api-key: '        . ANTHROPIC_API_KEY,
            'anthropic-version: 2023-06-01',
            'anthropic-beta: skills-2025-10-02',
        ],
    ]);
    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200) return [];

    $data   = json_decode($response, true);
    $skills = [];
    foreach ($data['data'] ?? [] as $skill) {
        $skills[] = [
            'value' => $skill['id'],
            'label' => $skill['display_title'],
        ];
    }
    usort($skills, fn($a, $b) => strcmp($a['label'], $b['label']));
    return $skills;
}
