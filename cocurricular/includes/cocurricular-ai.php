<?php
/**
 * Co-Curricular Module — Server-Side OpenAI GPT-4.1 AI Announcement Draft Generator
 * Encapsulates secure server-side cURL communication with OpenAI GPT-4.1 API
 * for generating announcement drafts for Bestlink College of the Philippines.
 */
declare(strict_types=1);

if (file_exists(__DIR__ . '/../config/cocurricular-config.php')) {
    require_once __DIR__ . '/../config/cocurricular-config.php';
}

require_once __DIR__ . '/../../../config/config.php';

if (!defined('COCURRICULAR_OPENAI_MODEL')) {
    define('COCURRICULAR_OPENAI_MODEL', 'gpt-4.1');
}

/**
 * Resolve the OpenAI API Key from server-side configuration safely.
 */
function cocurricularGetOpenAiApiKey(): ?string
{
    if (defined('OPENAI_API_KEY') && is_string(OPENAI_API_KEY) && trim(OPENAI_API_KEY) !== '') {
        return trim(OPENAI_API_KEY);
    }

    if (function_exists('sms2_env')) {
        $envKey = sms2_env('OPENAI_API_KEY', sms2_env('OPENAI_KEY'));
        if (is_string($envKey) && trim($envKey) !== '') {
            return trim($envKey);
        }
    }

    return null;
}

/**
 * Normalize and validate structured input fields for announcement draft generation.
 */
function cocurricularNormalizeAiInput(array $input): array
{
    $truncate = static fn(mixed $val, int $maxLen): string => mb_substr(trim((string) $val), 0, $maxLen);

    return [
        'title' => $truncate($input['title'] ?? '', 200),
        'topic' => $truncate($input['topic'] ?? '', 500),
        'details' => $truncate($input['details'] ?? '', 5000),
        'club_name' => $truncate($input['club_name'] ?? '', 200),
        'event_name' => $truncate($input['event_name'] ?? '', 200),
        'event_date' => $truncate($input['event_date'] ?? '', 100),
        'event_time' => $truncate($input['event_time'] ?? '', 100),
        'venue' => $truncate($input['venue'] ?? '', 300),
        'audience' => $truncate($input['audience'] ?? '', 300),
        'additional_instructions' => $truncate($input['additional_instructions'] ?? '', 1000),
    ];
}

/**
 * Generate a structured announcement draft using OpenAI GPT-4.1.
 *
 * @param array $input Structured announcement topic/event input details.
 * @return array Response array containing { success: bool, data?: array, error?: string, model?: string }
 */
function cocurricularGenerateAnnouncementDraft(array $input): array
{
    $normalized = cocurricularNormalizeAiInput($input);

    if ($normalized['title'] === '' && $normalized['topic'] === '' && $normalized['details'] === '' && $normalized['event_name'] === '') {
        return [
            'success' => false,
            'error' => 'Please provide a title, topic, event name, or details to generate an announcement draft.',
        ];
    }

    $apiKey = cocurricularGetOpenAiApiKey();
    if (!$apiKey) {
        return [
            'success' => false,
            'error' => 'OpenAI API key is missing or unconfigured. Please configure OPENAI_API_KEY in server configuration.',
        ];
    }

    $systemPrompt = <<<PROMPT
You are an expert AI announcement writer for the Co-Curricular Management System at Bestlink College of the Philippines.
Your role is to write clear, professional, engaging, student-friendly, and informative club announcements.

CRITICAL SAFETY AND FACTUALITY RULES:
1. You MUST NOT invent or fabricate factual details such as dates, times, venues, fees, contact information, speakers, or links that are not explicitly provided in the prompt.
2. Organize the provided details clearly using paragraphs, bullet points, or clean formatting suitable for college students.
3. Maintain an encouraging, academic, and professional tone appropriate for college campus activities.
4. Respond ONLY with a valid JSON object matching this exact schema:
{
  "title": "A concise, engaging announcement headline",
  "content": "The full announcement body text with proper formatting and structure",
  "summary": "A 1-2 sentence preview summary of the announcement"
}
5. Do not include markdown code fence formatting (e.g. ```json) around your JSON output. Return pure raw JSON string only.
PROMPT;

    $userPromptParts = [];
    if ($normalized['club_name'] !== '') {
        $userPromptParts[] = "Club Name: {$normalized['club_name']}";
    }
    if ($normalized['event_name'] !== '') {
        $userPromptParts[] = "Event Name: {$normalized['event_name']}";
    }
    if ($normalized['title'] !== '') {
        $userPromptParts[] = "Draft Title Idea: {$normalized['title']}";
    }
    if ($normalized['topic'] !== '') {
        $userPromptParts[] = "Topic / Theme: {$normalized['topic']}";
    }
    if ($normalized['details'] !== '') {
        $userPromptParts[] = "Key Announcement Details:\n{$normalized['details']}";
    }
    if ($normalized['event_date'] !== '') {
        $userPromptParts[] = "Date: {$normalized['event_date']}";
    }
    if ($normalized['event_time'] !== '') {
        $userPromptParts[] = "Time: {$normalized['event_time']}";
    }
    if ($normalized['venue'] !== '') {
        $userPromptParts[] = "Venue / Location: {$normalized['venue']}";
    }
    if ($normalized['audience'] !== '') {
        $userPromptParts[] = "Target Audience: {$normalized['audience']}";
    }
    if ($normalized['additional_instructions'] !== '') {
        $userPromptParts[] = "Special Instructions / Tone: {$normalized['additional_instructions']}";
    }

    $userPrompt = "Please generate a structured Co-Curricular announcement draft based on the following details:\n\n" . implode("\n", $userPromptParts);

    $payload = [
        'model' => COCURRICULAR_OPENAI_MODEL,
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt],
        ],
        'temperature' => 0.7,
        'response_format' => ['type' => 'json_object'],
    ];

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    if (!$ch) {
        return [
            'success' => false,
            'error' => 'Unable to initialize cURL HTTP client.',
        ];
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
    ]);

    $rawResponse = curl_exec($ch);
    $curlErrno = curl_errno($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlErrno !== 0) {
        error_log('Cocurricular OpenAI cURL Error (' . $curlErrno . '): ' . $curlError);
        return [
            'success' => false,
            'error' => 'Unable to communicate with OpenAI API service. Network timeout or connection failure.',
        ];
    }

    if ($httpCode !== 200 || !$rawResponse) {
        error_log('Cocurricular OpenAI HTTP Error ' . $httpCode . ' Response: ' . (string) $rawResponse);
        return [
            'success' => false,
            'error' => 'OpenAI service error (HTTP ' . $httpCode . '). Unable to generate announcement draft.',
        ];
    }

    try {
        $data = json_decode((string) $rawResponse, true, 512, JSON_THROW_ON_ERROR);
        $contentRaw = trim((string) ($data['choices'][0]['message']['content'] ?? ''));

        if ($contentRaw === '') {
            return [
                'success' => false,
                'error' => 'OpenAI returned an empty announcement response.',
            ];
        }

        // Strip markdown code fences if returned despite system prompt instruction
        if (str_starts_with($contentRaw, '```json')) {
            $contentRaw = preg_replace('/^```json\s*/i', '', $contentRaw);
            $contentRaw = preg_replace('/\s*```$/', '', $contentRaw);
        } elseif (str_starts_with($contentRaw, '```')) {
            $contentRaw = preg_replace('/^```\s*/', '', $contentRaw);
            $contentRaw = preg_replace('/\s*```$/', '', $contentRaw);
        }

        $parsed = json_decode(trim($contentRaw), true, 512, JSON_THROW_ON_ERROR);

        $title = trim((string) ($parsed['title'] ?? ''));
        $content = trim((string) ($parsed['content'] ?? ''));
        $summary = trim((string) ($parsed['summary'] ?? ''));

        if ($title === '' || $content === '') {
            return [
                'success' => false,
                'error' => 'OpenAI draft output was missing required title or content fields.',
            ];
        }

        return [
            'success' => true,
            'data' => [
                'title' => $title,
                'content' => $content,
                'summary' => $summary !== '' ? $summary : mb_substr($content, 0, 150) . '...',
            ],
            'model' => COCURRICULAR_OPENAI_MODEL,
            'generated_at' => date('Y-m-d H:i:s'),
        ];
    } catch (Throwable $e) {
        error_log('Cocurricular OpenAI JSON Parse Error: ' . $e->getMessage());
        return [
            'success' => false,
            'error' => 'Failed to parse AI announcement response JSON structure.',
        ];
    }
}
