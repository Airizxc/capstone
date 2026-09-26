<?php
/**
 * Co-Curricular Module — Server-Side AI Announcement Draft Generator
 * Encapsulates secure server-side cURL communication with Gemini API (Primary)
 * and OpenAI API (Secondary) with resilient local synthesizer fallback for Bestlink College of the Philippines.
 */
declare(strict_types=1);

if (file_exists(__DIR__ . '/../config/cocurricular-config.php')) {
    require_once __DIR__ . '/../config/cocurricular-config.php';
}

require_once __DIR__ . '/../../../config/config.php';

if (!defined('COCURRICULAR_GEMINI_MODEL')) {
    define('COCURRICULAR_GEMINI_MODEL', 'gemini-3.6-flash');
}

if (!defined('COCURRICULAR_OPENAI_MODEL')) {
    define('COCURRICULAR_OPENAI_MODEL', 'gpt-4.1');
}

/**
 * Resolve the Gemini API Key from server-side configuration safely.
 * Checks GEMINI_API_KEY constant, cocurricular_env(), sms2_env(), and getenv()/$_ENV/$_SERVER.
 * NEVER prints, echoes, exposes, or logs the key.
 */
function cocurricularGetGeminiApiKey(): ?string
{
    if (defined('GEMINI_API_KEY') && is_string(GEMINI_API_KEY) && trim(GEMINI_API_KEY) !== '') {
        return trim(GEMINI_API_KEY);
    }

    if (function_exists('cocurricular_env')) {
        $envKey = cocurricular_env('GEMINI_API_KEY');
        if (is_string($envKey) && trim($envKey) !== '') {
            return trim($envKey);
        }
    }

    if (function_exists('sms2_env')) {
        $envKey = sms2_env('GEMINI_API_KEY');
        if (is_string($envKey) && trim($envKey) !== '') {
            return trim($envKey);
        }
    }

    $sysEnv = getenv('GEMINI_API_KEY');
    if (is_string($sysEnv) && trim($sysEnv) !== '') {
        return trim($sysEnv);
    }

    if (isset($_ENV['GEMINI_API_KEY']) && is_string($_ENV['GEMINI_API_KEY']) && trim($_ENV['GEMINI_API_KEY']) !== '') {
        return trim($_ENV['GEMINI_API_KEY']);
    }

    if (isset($_SERVER['GEMINI_API_KEY']) && is_string($_SERVER['GEMINI_API_KEY']) && trim($_SERVER['GEMINI_API_KEY']) !== '') {
        return trim($_SERVER['GEMINI_API_KEY']);
    }

    return null;
}

/**
 * Resolve the OpenAI API Key from server-side configuration safely.
 */
function cocurricularGetOpenAiApiKey(): ?string
{
    if (defined('OPENAI_API_KEY') && is_string(OPENAI_API_KEY) && trim(OPENAI_API_KEY) !== '') {
        return trim(OPENAI_API_KEY);
    }

    if (function_exists('cocurricular_env')) {
        $envKey = cocurricular_env('OPENAI_API_KEY', cocurricular_env('OPENAI_KEY'));
        if (is_string($envKey) && trim($envKey) !== '') {
            return trim($envKey);
        }
    }

    if (function_exists('sms2_env')) {
        $envKey = sms2_env('OPENAI_API_KEY', sms2_env('OPENAI_KEY'));
        if (is_string($envKey) && trim($envKey) !== '') {
            return trim($envKey);
        }
    }

    $sysEnv = getenv('OPENAI_API_KEY') ?: getenv('OPENAI_KEY');
    if (is_string($sysEnv) && trim($sysEnv) !== '') {
        return trim($sysEnv);
    }

    if (isset($_ENV['OPENAI_API_KEY']) && is_string($_ENV['OPENAI_API_KEY']) && trim($_ENV['OPENAI_API_KEY']) !== '') {
        return trim($_ENV['OPENAI_API_KEY']);
    }

    if (isset($_SERVER['OPENAI_API_KEY']) && is_string($_SERVER['OPENAI_API_KEY']) && trim($_SERVER['OPENAI_API_KEY']) !== '') {
        return trim($_SERVER['OPENAI_API_KEY']);
    }

    return null;
}

/**
 * Log structured, safe AI diagnostics without exposing keys or headers.
 */
function cocurricularLogAiDiagnostic(string $endpoint, array $ctx): void
{
    $msg = sprintf(
        '[Cocurricular AI] endpoint=%s club_id=%s user_id=%s provider=%s api_key_available=%s http_code=%s curl_errno=%s error_type=%s fallback_used=%s',
        $endpoint,
        (string) ($ctx['club_id'] ?? 'none'),
        (string) ($ctx['user_id'] ?? 'none'),
        (string) ($ctx['provider'] ?? 'none'),
        !empty($ctx['api_key_available']) ? 'yes' : 'no',
        (string) ($ctx['http_code'] ?? 'none'),
        (string) ($ctx['curl_errno'] ?? '0'),
        (string) ($ctx['error_type'] ?? $ctx['openai_error_type'] ?? 'none'),
        !empty($ctx['fallback_used']) ? 'yes' : 'no'
    );
    error_log($msg);
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
 * Build standardized system and user prompt strings strictly preserving anti-hallucination rules.
 */
function cocurricularBuildAnnouncementPrompt(array $normalized): array
{
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

    return [$systemPrompt, $userPrompt];
}

/**
 * Generate a structured announcement draft using Google Gemini REST API.
 * Uses x-goog-api-key server-side header and defensive parsing.
 *
 * @param array $normalized Normalized announcement input fields.
 * @param array $diagnosticContext Diagnostic logging context.
 * @return array Result array { success: bool, data?: array, draft?: array, model?: string, provider: string, source: string, fallback_used: bool, error?: string, generated_at?: string }
 */
function cocurricularGenerateAnnouncementWithGemini(array $normalized, array $diagnosticContext = []): array
{
    $apiKey = cocurricularGetGeminiApiKey();
    if ($apiKey === null || $apiKey === '') {
        return [
            'success' => false,
            'error' => 'Gemini API key is not configured.',
            'provider' => 'gemini',
            'fallback_used' => false,
        ];
    }

    if (!function_exists('curl_init')) {
        cocurricularLogAiDiagnostic($diagnosticContext['endpoint'] ?? 'generate-announcement', array_merge($diagnosticContext, [
            'provider' => 'gemini',
            'api_key_available' => true,
            'fallback_used' => false,
            'error_type' => 'curl_extension_unavailable',
        ]));
        return [
            'success' => false,
            'error' => 'cURL extension is unavailable.',
            'provider' => 'gemini',
            'fallback_used' => false,
        ];
    }

    [$systemPrompt, $userPrompt] = cocurricularBuildAnnouncementPrompt($normalized);

    $primaryModel = defined('COCURRICULAR_GEMINI_MODEL') ? COCURRICULAR_GEMINI_MODEL : 'gemini-3.6-flash';
    $modelsToTry = [$primaryModel];
    if ($primaryModel !== 'gemini-3.5-flash-lite') {
        $modelsToTry[] = 'gemini-3.5-flash-lite';
    }

    $lastHttpCode = 0;
    $lastErrType = 'none';

    foreach ($modelsToTry as $model) {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";

        $payload = [
            'system_instruction' => [
                'parts' => [
                    ['text' => $systemPrompt]
                ]
            ],
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $userPrompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'temperature' => 0.7,
            ]
        ];

        $ch = curl_init($url);
        if (!$ch) {
            continue;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-goog-api-key: ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
        ]);

        $rawResponse = curl_exec($ch);
        $curlErrno = (int) curl_errno($ch);
        $curlError = (string) curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $lastHttpCode = $httpCode;

        if ($curlErrno !== 0) {
            cocurricularLogAiDiagnostic($diagnosticContext['endpoint'] ?? 'generate-announcement', array_merge($diagnosticContext, [
                'provider' => 'gemini',
                'api_key_available' => true,
                'curl_errno' => $curlErrno,
                'fallback_used' => false,
                'error_type' => 'curl_network_error: ' . $curlError,
            ]));
            continue;
        }

        if ($httpCode !== 200 || empty($rawResponse)) {
            $errType = 'http_' . $httpCode;
            if (!empty($rawResponse)) {
                $errDecoded = json_decode((string) $rawResponse, true);
                if (isset($errDecoded['error']['status']) || isset($errDecoded['error']['code'])) {
                    $errType .= ':' . ($errDecoded['error']['status'] ?? $errDecoded['error']['code']);
                }
            }
            $lastErrType = $errType;
            cocurricularLogAiDiagnostic($diagnosticContext['endpoint'] ?? 'generate-announcement', array_merge($diagnosticContext, [
                'provider' => 'gemini',
                'api_key_available' => true,
                'http_code' => $httpCode,
                'fallback_used' => false,
                'error_type' => $errType . ' (model: ' . $model . ')',
            ]));

            // On temporary spike or unavailable model, try the next flash model
            if ($httpCode === 503 || $httpCode === 404) {
                continue;
            }
            break;
        }

        try {
            $data = json_decode((string) $rawResponse, true, 512, JSON_THROW_ON_ERROR);
            $contentRaw = trim((string) ($data['candidates'][0]['content']['parts'][0]['text'] ?? ''));

            if ($contentRaw === '') {
                cocurricularLogAiDiagnostic($diagnosticContext['endpoint'] ?? 'generate-announcement', array_merge($diagnosticContext, [
                    'provider' => 'gemini',
                    'api_key_available' => true,
                    'http_code' => 200,
                    'fallback_used' => false,
                    'error_type' => 'empty_gemini_candidate_text',
                ]));
                continue;
            }

            // Defensively strip markdown code fences (```json ... ``` or ``` ... ```)
            if (str_starts_with($contentRaw, '```json')) {
                $contentRaw = preg_replace('/^```json\s*/i', '', $contentRaw);
                $contentRaw = preg_replace('/\s*```$/', '', $contentRaw);
            } elseif (str_starts_with($contentRaw, '```')) {
                $contentRaw = preg_replace('/^```\s*/', '', $contentRaw);
                $contentRaw = preg_replace('/\s*```$/', '', $contentRaw);
            }

            $parsed = json_decode(trim((string) $contentRaw), true, 512, JSON_THROW_ON_ERROR);
            $title = trim((string) ($parsed['title'] ?? ''));
            $content = trim((string) ($parsed['content'] ?? ''));
            $summary = trim((string) ($parsed['summary'] ?? ''));

            if ($title === '' || $content === '') {
                cocurricularLogAiDiagnostic($diagnosticContext['endpoint'] ?? 'generate-announcement', array_merge($diagnosticContext, [
                    'provider' => 'gemini',
                    'api_key_available' => true,
                    'http_code' => 200,
                    'fallback_used' => false,
                    'error_type' => 'missing_title_or_content',
                ]));
                continue;
            }

            $draftPayload = [
                'title' => $title,
                'content' => $content,
                'summary' => $summary !== '' ? $summary : mb_substr($content, 0, 150) . '...',
            ];

            cocurricularLogAiDiagnostic($diagnosticContext['endpoint'] ?? 'generate-announcement', array_merge($diagnosticContext, [
                'provider' => 'gemini',
                'api_key_available' => true,
                'http_code' => 200,
                'fallback_used' => false,
                'error_type' => 'none',
            ]));

            return [
                'success' => true,
                'data' => $draftPayload,
                'draft' => $draftPayload,
                'model' => $model,
                'source' => 'gemini',
                'provider' => 'gemini',
                'fallback_used' => false,
                'generated_at' => date('Y-m-d H:i:s'),
            ];
        } catch (Throwable $e) {
            cocurricularLogAiDiagnostic($diagnosticContext['endpoint'] ?? 'generate-announcement', array_merge($diagnosticContext, [
                'provider' => 'gemini',
                'api_key_available' => true,
                'http_code' => 200,
                'fallback_used' => false,
                'error_type' => 'json_parse_exception: ' . $e->getMessage(),
            ]));
            continue;
        }
    }

    return [
        'success' => false,
        'error' => 'Gemini API Error: HTTP ' . $lastHttpCode,
        'provider' => 'gemini',
        'fallback_used' => false,
    ];
}

/**
 * Generate a structured announcement draft using OpenAI API.
 *
 * @param array $normalized Normalized announcement input fields.
 * @param array $diagnosticContext Diagnostic logging context.
 * @return array Result array { success: bool, data?: array, draft?: array, model?: string, provider: string, source: string, fallback_used: bool, error?: string, generated_at?: string }
 */
function cocurricularGenerateAnnouncementWithOpenAi(array $normalized, array $diagnosticContext = []): array
{
    $apiKey = cocurricularGetOpenAiApiKey();
    if ($apiKey === null || $apiKey === '') {
        return [
            'success' => false,
            'error' => 'OpenAI API key is not configured.',
            'provider' => 'openai',
            'fallback_used' => false,
        ];
    }

    if (!function_exists('curl_init')) {
        cocurricularLogAiDiagnostic($diagnosticContext['endpoint'] ?? 'generate-announcement', array_merge($diagnosticContext, [
            'provider' => 'openai',
            'api_key_available' => true,
            'fallback_used' => false,
            'error_type' => 'curl_extension_unavailable',
        ]));
        return [
            'success' => false,
            'error' => 'cURL extension is unavailable.',
            'provider' => 'openai',
            'fallback_used' => false,
        ];
    }

    [$systemPrompt, $userPrompt] = cocurricularBuildAnnouncementPrompt($normalized);

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
        cocurricularLogAiDiagnostic($diagnosticContext['endpoint'] ?? 'generate-announcement', array_merge($diagnosticContext, [
            'provider' => 'openai',
            'api_key_available' => true,
            'fallback_used' => false,
            'error_type' => 'curl_init_failed',
        ]));
        return [
            'success' => false,
            'error' => 'Failed to initialize cURL.',
            'provider' => 'openai',
            'fallback_used' => false,
        ];
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
    ]);

    $rawResponse = curl_exec($ch);
    $curlErrno = (int) curl_errno($ch);
    $curlError = (string) curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlErrno !== 0) {
        cocurricularLogAiDiagnostic($diagnosticContext['endpoint'] ?? 'generate-announcement', array_merge($diagnosticContext, [
            'provider' => 'openai',
            'api_key_available' => true,
            'curl_errno' => $curlErrno,
            'fallback_used' => false,
            'error_type' => 'curl_network_error: ' . $curlError,
        ]));
        return [
            'success' => false,
            'error' => 'OpenAI network connection error.',
            'provider' => 'openai',
            'fallback_used' => false,
        ];
    }

    if ($httpCode !== 200 || empty($rawResponse)) {
        $errType = 'http_' . $httpCode;
        if (!empty($rawResponse)) {
            $errDecoded = json_decode((string) $rawResponse, true);
            if (isset($errDecoded['error']['type']) || isset($errDecoded['error']['code'])) {
                $errType .= ':' . ($errDecoded['error']['code'] ?? $errDecoded['error']['type']);
            }
        }
        cocurricularLogAiDiagnostic($diagnosticContext['endpoint'] ?? 'generate-announcement', array_merge($diagnosticContext, [
            'provider' => 'openai',
            'api_key_available' => true,
            'http_code' => $httpCode,
            'fallback_used' => false,
            'error_type' => $errType,
        ]));
        return [
            'success' => false,
            'error' => 'OpenAI API HTTP Error ' . $httpCode,
            'provider' => 'openai',
            'fallback_used' => false,
        ];
    }

    try {
        $data = json_decode((string) $rawResponse, true, 512, JSON_THROW_ON_ERROR);
        $contentRaw = trim((string) ($data['choices'][0]['message']['content'] ?? ''));

        if ($contentRaw === '') {
            cocurricularLogAiDiagnostic($diagnosticContext['endpoint'] ?? 'generate-announcement', array_merge($diagnosticContext, [
                'provider' => 'openai',
                'api_key_available' => true,
                'http_code' => 200,
                'fallback_used' => false,
                'error_type' => 'empty_choices_content',
            ]));
            return [
                'success' => false,
                'error' => 'Empty content returned by OpenAI.',
                'provider' => 'openai',
                'fallback_used' => false,
            ];
        }

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
            cocurricularLogAiDiagnostic($diagnosticContext['endpoint'] ?? 'generate-announcement', array_merge($diagnosticContext, [
                'provider' => 'openai',
                'api_key_available' => true,
                'http_code' => 200,
                'fallback_used' => false,
                'error_type' => 'missing_title_or_content',
            ]));
            return [
                'success' => false,
                'error' => 'Draft is missing title or content.',
                'provider' => 'openai',
                'fallback_used' => false,
            ];
        }

        $draftPayload = [
            'title' => $title,
            'content' => $content,
            'summary' => $summary !== '' ? $summary : mb_substr($content, 0, 150) . '...',
        ];

        cocurricularLogAiDiagnostic($diagnosticContext['endpoint'] ?? 'generate-announcement', array_merge($diagnosticContext, [
            'provider' => 'openai',
            'api_key_available' => true,
            'http_code' => 200,
            'fallback_used' => false,
            'error_type' => 'none',
        ]));

        return [
            'success' => true,
            'data' => $draftPayload,
            'draft' => $draftPayload,
            'model' => COCURRICULAR_OPENAI_MODEL,
            'source' => 'openai',
            'provider' => 'openai',
            'fallback_used' => false,
            'generated_at' => date('Y-m-d H:i:s'),
        ];
    } catch (Throwable $e) {
        cocurricularLogAiDiagnostic($diagnosticContext['endpoint'] ?? 'generate-announcement', array_merge($diagnosticContext, [
            'provider' => 'openai',
            'api_key_available' => true,
            'http_code' => 200,
            'fallback_used' => false,
            'error_type' => 'json_parse_exception: ' . $e->getMessage(),
        ]));
        return [
            'success' => false,
            'error' => 'Malformed JSON returned by OpenAI.',
            'provider' => 'openai',
            'fallback_used' => false,
        ];
    }
}

/**
 * Generate a structured announcement draft using the multi-provider priority chain:
 * 1. Google Gemini (Primary)
 * 2. OpenAI (Secondary)
 * 3. Local Academic Synthesizer (Fallback)
 *
 * @param array $input Structured announcement topic/event input details.
 * @param array $diagnosticContext Optional context for diagnostics (club_id, user_id, endpoint).
 * @return array Response array containing { success: bool, data: array, draft: array, model: string, provider: string, source: string, fallback_used: bool, generated_at: string }
 */
function cocurricularGenerateAnnouncementDraft(array $input, array $diagnosticContext = []): array
{
    $normalized = cocurricularNormalizeAiInput($input);

    if ($normalized['title'] === '' && $normalized['topic'] === '' && $normalized['details'] === '' && $normalized['event_name'] === '') {
        return [
            'success' => false,
            'error' => 'Please provide a title, topic, event name, or details to generate an announcement draft.',
        ];
    }

    // 1. Primary AI Provider: Google Gemini
    $geminiKey = cocurricularGetGeminiApiKey();
    if ($geminiKey !== null && $geminiKey !== '') {
        $geminiResult = cocurricularGenerateAnnouncementWithGemini($normalized, $diagnosticContext);
        if (!empty($geminiResult['success'])) {
            return $geminiResult;
        }
    } else {
        cocurricularLogAiDiagnostic($diagnosticContext['endpoint'] ?? 'generate-announcement', array_merge($diagnosticContext, [
            'provider' => 'gemini',
            'api_key_available' => false,
            'fallback_used' => false,
            'error_type' => 'gemini_api_key_missing',
        ]));
    }

    // 2. Secondary AI Provider: OpenAI
    $openAiKey = cocurricularGetOpenAiApiKey();
    if ($openAiKey !== null && $openAiKey !== '') {
        $openAiResult = cocurricularGenerateAnnouncementWithOpenAi($normalized, $diagnosticContext);
        if (!empty($openAiResult['success'])) {
            return $openAiResult;
        }
    } else {
        cocurricularLogAiDiagnostic($diagnosticContext['endpoint'] ?? 'generate-announcement', array_merge($diagnosticContext, [
            'provider' => 'openai',
            'api_key_available' => false,
            'fallback_used' => false,
            'error_type' => 'openai_api_key_missing',
        ]));
    }

    // 3. Resilient Local Academic Synthesizer Fallback
    cocurricularLogAiDiagnostic($diagnosticContext['endpoint'] ?? 'generate-announcement', array_merge($diagnosticContext, [
        'provider' => 'local',
        'api_key_available' => false,
        'fallback_used' => true,
        'error_type' => 'all_ai_providers_exhausted',
    ]));

    return cocurricularSynthesizeAnnouncementDraft($normalized);
}

/**
 * Synthesizes a high-quality, professional academic announcement draft from structured inputs
 * when external AI providers are unreachable, rate-limited, or out-of-credits.
 */
function cocurricularSynthesizeAnnouncementDraft(array $normalized): array
{
    $clubName = $normalized['club_name'] !== '' ? $normalized['club_name'] : 'Student Organization';
    $topic = $normalized['topic'] !== '' ? $normalized['topic'] : ($normalized['title'] !== '' ? $normalized['title'] : 'Important Club Announcement');
    $eventName = $normalized['event_name'] !== '' ? $normalized['event_name'] : '';
    $details = $normalized['details'] !== '' ? $normalized['details'] : '';
    $date = $normalized['event_date'] !== '' ? $normalized['event_date'] : '';
    $time = $normalized['event_time'] !== '' ? $normalized['event_time'] : '';
    $venue = $normalized['venue'] !== '' ? $normalized['venue'] : '';
    $audience = $normalized['audience'] !== '' ? $normalized['audience'] : 'All club members';
    $tone = $normalized['additional_instructions'] !== '' ? strtolower($normalized['additional_instructions']) : 'professional';

    // Formulate a crisp headline
    if ($eventName !== '') {
        $title = "Official Notice: {$eventName} — {$clubName}";
    } elseif ($normalized['title'] !== '') {
        $title = $normalized['title'];
    } elseif (stripos($topic, 'Notice') !== false || stripos($topic, 'Announcement') !== false) {
        $title = $topic;
    } else {
        $title = "Notice of {$topic} — {$clubName}";
    }

    // Build structured body text
    $lines = [];
    if (str_contains($tone, 'engaging') || str_contains($tone, 'welcoming')) {
        $lines[] = "Greetings, members and friends of {$clubName}!";
        $lines[] = "";
        $lines[] = "We are pleased to invite you to our upcoming {$topic}.";
    } elseif (str_contains($tone, 'urgent') || str_contains($tone, 'reminder')) {
        $lines[] = "IMPORTANT NOTICE FOR ALL MEMBERS — {$clubName}";
        $lines[] = "";
        $lines[] = "Please be advised of an urgent schedule and announcement regarding {$topic}:";
    } else {
        $lines[] = "Dear Members of {$clubName},";
        $lines[] = "";
        $lines[] = "Please be informed of the upcoming {$topic} organized by {$clubName}.";
    }

    if ($details !== '') {
        $lines[] = "";
        $lines[] = "Activity Outline & Key Points:";
        $lines[] = $details;
    }

    $meta = [];
    if ($date !== '') $meta[] = "• Date: {$date}";
    if ($time !== '') $meta[] = "• Time: {$time}";
    if ($venue !== '') $meta[] = "• Venue / Location: {$venue}";
    if ($audience !== '') $meta[] = "• Target Audience: {$audience}";

    if (!empty($meta)) {
        $lines[] = "";
        $lines[] = "Schedule & Attendance Details:";
        foreach ($meta as $m) {
            $lines[] = $m;
        }
    }

    $lines[] = "";
    $lines[] = "Please mark your calendars and be guided accordingly. For any inquiries, please coordinate with the club officers or faculty adviser.";
    $lines[] = "";
    $lines[] = "Best regards,";
    $lines[] = "Office of the Faculty Adviser";
    $lines[] = $clubName;

    $content = implode("\n", $lines);
    $summary = "Official notice regarding {$topic} for {$clubName}.";

    $draftPayload = [
        'title' => $title,
        'content' => $content,
        'summary' => $summary,
    ];

    return [
        'success' => true,
        'data' => $draftPayload,
        'draft' => $draftPayload,
        'model' => 'local-fallback',
        'source' => 'local',
        'provider' => 'local',
        'fallback_used' => true,
        'generated_at' => date('Y-m-d H:i:s'),
    ];
}

/**
 * =========================================================================
 * EVENT DESCRIPTION GENERATION PIPELINE
 * Assists Faculty Advisers in drafting professional event descriptions
 * directly within the Schedule Club Event creation form.
 * Priority: Gemini -> OpenAI fallback -> Local Synthesizer fallback.
 * =========================================================================
 */

/**
 * Build system and user prompt for event description generation.
 */
function cocurricularBuildEventDescriptionPrompt(array $input): array
{
    $title = trim((string) ($input['title'] ?? ''));
    $eventType = trim((string) ($input['event_type'] ?? 'General Meeting'));
    $clubName = trim((string) ($input['club_name'] ?? 'Student Organization'));
    $eventDate = trim((string) ($input['event_date'] ?? ''));
    $startTime = trim((string) ($input['start_time'] ?? ''));
    $endTime = trim((string) ($input['end_time'] ?? ''));
    $venue = trim((string) ($input['venue'] ?? ''));
    $currentDesc = trim((string) ($input['current_description'] ?? ''));
    $instructions = trim((string) ($input['additional_instructions'] ?? ''));

    $timeStr = $startTime !== '' ? ($endTime !== '' ? "{$startTime} – {$endTime}" : $startTime) : '';

    $systemPrompt = <<<PROMPT
You are an expert academic communications assistant for Bestlink College of the Philippines (BCP) Co-Curricular Student Affairs.
Your task is to write a clear, professional, engaging, and well-structured event description for an upcoming student organization activity.

GUIDELINES:
1. Adapt tone and structure specifically based on the Event Type:
   - General Assembly: Focus on organization updates, member participation, leadership introduction, orientation, and general agenda.
   - Competition / Contest: Emphasize the challenge, participant categories, technical/creative skills, competition purpose, and spirit of academic excellence.
   - Seminar / Workshop: Highlight practical learning objectives, topic depth, targeted student participants, hands-on takeaways, and expected outcomes.
   - Meeting: Detail officer/committee purpose, agenda items, attendees, and strategic planning goals.
   - Community Activity / Outreach: Emphasize community purpose, beneficiaries, volunteer engagement, social responsibility, and positive impact.
   - Other / Social Event: Focus on student camaraderie, networking, active engagement, and team collaboration.
2. Ground all information in the provided event details. Do NOT invent dates, guest speakers, or policies not provided.
3. Write 2 to 3 concise, coherent paragraphs suitable for college students and faculty.
4. Output MUST be valid JSON with exact keys:
   {
     "description": "The complete, polished event description paragraphs...",
     "summary": "A 1-sentence executive summary of the activity."
   }
PROMPT;

    $userPrompt = "Event Title: {$title}\n";
    $userPrompt .= "Club/Organization: {$clubName}\n";
    $userPrompt .= "Event Type: {$eventType}\n";
    if ($eventDate !== '') $userPrompt .= "Event Date: {$eventDate}\n";
    if ($timeStr !== '') $userPrompt .= "Time Schedule: {$timeStr}\n";
    if ($venue !== '') $userPrompt .= "Venue: {$venue}\n";
    if ($currentDesc !== '') $userPrompt .= "Draft / Existing Notes: {$currentDesc}\n";
    if ($instructions !== '') $userPrompt .= "Additional Instructions: {$instructions}\n";
    $userPrompt .= "\nPlease generate the JSON response now.";

    return [$systemPrompt, $userPrompt];
}

/**
 * Generate Event Description using Gemini API.
 */
function cocurricularGenerateEventDescriptionWithGemini(array $input, array $diagnosticContext = []): array
{
    $apiKey = cocurricularGetGeminiApiKey();
    if ($apiKey === null || $apiKey === '') {
        return [
            'success' => false,
            'error' => 'Gemini API key is not configured.',
            'provider' => 'gemini',
            'fallback_used' => false,
        ];
    }

    if (!function_exists('curl_init')) {
        return [
            'success' => false,
            'error' => 'cURL extension is unavailable.',
            'provider' => 'gemini',
            'fallback_used' => false,
        ];
    }

    [$systemPrompt, $userPrompt] = cocurricularBuildEventDescriptionPrompt($input);

    $primaryModel = defined('COCURRICULAR_GEMINI_MODEL') ? COCURRICULAR_GEMINI_MODEL : 'gemini-3.6-flash';
    $modelsToTry = [$primaryModel];
    if ($primaryModel !== 'gemini-3.5-flash-lite') {
        $modelsToTry[] = 'gemini-3.5-flash-lite';
    }

    $lastHttpCode = 0;
    $lastErrType = 'none';

    foreach ($modelsToTry as $model) {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";

        $payload = [
            'system_instruction' => [
                'parts' => [
                    ['text' => $systemPrompt]
                ]
            ],
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $userPrompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'temperature' => 0.7,
            ]
        ];

        $ch = curl_init($url);
        if (!$ch) {
            continue;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json; charset=utf-8',
                'x-goog-api-key: ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);

        $rawResponse = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrno = curl_errno($ch);
        curl_close($ch);

        $lastHttpCode = $httpCode;

        if ($curlErrno !== 0 || $rawResponse === false) {
            $lastErrType = 'curl_network_error';
            continue;
        }

        if ($httpCode !== 200) {
            $lastErrType = ($httpCode === 429) ? 'rate_limit_exceeded' : 'http_error_' . $httpCode;
            continue;
        }

        $decoded = json_decode((string) $rawResponse, true);
        $candidateText = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? '';
        if ($candidateText === '') {
            $lastErrType = 'empty_candidate_text';
            continue;
        }

        $parsed = json_decode(trim($candidateText), true);
        if (!is_array($parsed) || empty($parsed['description'])) {
            // Strip markdown block if present
            $cleaned = preg_replace('/^```(?:json)?\s*/i', '', trim($candidateText));
            $cleaned = preg_replace('/\s*```$/', '', (string) $cleaned);
            $parsed = json_decode((string) $cleaned, true);
        }

        if (is_array($parsed) && !empty($parsed['description'])) {
            cocurricularLogAiDiagnostic($diagnosticContext['endpoint'] ?? 'generate-event-description', array_merge($diagnosticContext, [
                'provider' => 'gemini',
                'model' => $model,
                'api_key_available' => true,
                'http_code' => 200,
                'curl_errno' => 0,
                'error_type' => 'none',
                'fallback_used' => false,
            ]));

            return [
                'success' => true,
                'description' => trim((string) $parsed['description']),
                'summary' => trim((string) ($parsed['summary'] ?? '')),
                'model' => $model,
                'provider' => 'gemini',
                'source' => 'api',
                'fallback_used' => false,
                'generated_at' => date('Y-m-d H:i:s'),
            ];
        }
    }

    cocurricularLogAiDiagnostic($diagnosticContext['endpoint'] ?? 'generate-event-description', array_merge($diagnosticContext, [
        'provider' => 'gemini',
        'api_key_available' => true,
        'http_code' => $lastHttpCode,
        'error_type' => $lastErrType,
        'fallback_used' => true,
    ]));

    return [
        'success' => false,
        'error' => 'Gemini API call was unsuccessful (code ' . $lastHttpCode . ').',
        'provider' => 'gemini',
        'fallback_used' => true,
    ];
}

/**
 * Generate Event Description using OpenAI API as secondary fallback.
 */
function cocurricularGenerateEventDescriptionWithOpenAi(array $input, array $diagnosticContext = []): array
{
    $apiKey = cocurricularGetOpenAiApiKey();
    if ($apiKey === null || $apiKey === '') {
        return [
            'success' => false,
            'error' => 'OpenAI API key is not configured.',
            'provider' => 'openai',
            'fallback_used' => false,
        ];
    }

    [$systemPrompt, $userPrompt] = cocurricularBuildEventDescriptionPrompt($input);
    $model = defined('COCURRICULAR_OPENAI_MODEL') ? COCURRICULAR_OPENAI_MODEL : 'gpt-4.1';

    $payload = [
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt],
        ],
        'response_format' => ['type' => 'json_object'],
        'temperature' => 0.7,
    ];

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    if (!$ch) {
        return ['success' => false, 'error' => 'Failed to initialize cURL for OpenAI.'];
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json; charset=utf-8',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    ]);

    $rawResponse = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErrno = curl_errno($ch);
    curl_close($ch);

    if ($curlErrno === 0 && $httpCode === 200 && is_string($rawResponse)) {
        $decoded = json_decode($rawResponse, true);
        $content = $decoded['choices'][0]['message']['content'] ?? '';
        $parsed = json_decode(trim((string) $content), true);
        if (is_array($parsed) && !empty($parsed['description'])) {
            cocurricularLogAiDiagnostic($diagnosticContext['endpoint'] ?? 'generate-event-description', array_merge($diagnosticContext, [
                'provider' => 'openai',
                'model' => $model,
                'api_key_available' => true,
                'http_code' => 200,
                'error_type' => 'none',
                'fallback_used' => false,
            ]));

            return [
                'success' => true,
                'description' => trim((string) $parsed['description']),
                'summary' => trim((string) ($parsed['summary'] ?? '')),
                'model' => $model,
                'provider' => 'openai',
                'source' => 'api',
                'fallback_used' => false,
                'generated_at' => date('Y-m-d H:i:s'),
            ];
        }
    }

    return [
        'success' => false,
        'error' => 'OpenAI generation failed.',
        'provider' => 'openai',
        'fallback_used' => true,
    ];
}

/**
 * Deterministic local fallback generator for Event Description.
 * Tailored specifically by Event Type to ensure resilience and high quality.
 */
function cocurricularSynthesizeEventDescription(array $input): array
{
    $title = trim((string) ($input['title'] ?? 'Upcoming Event'));
    $eventType = trim((string) ($input['event_type'] ?? 'General Meeting'));
    $clubName = trim((string) ($input['club_name'] ?? 'Student Organization'));
    $eventDate = trim((string) ($input['event_date'] ?? ''));
    $startTime = trim((string) ($input['start_time'] ?? ''));
    $endTime = trim((string) ($input['end_time'] ?? ''));
    $venue = trim((string) ($input['venue'] ?? 'Campus Venue'));
    $instructions = trim((string) ($input['additional_instructions'] ?? ''));

    $timeStr = $startTime !== '' ? ($endTime !== '' ? "{$startTime} – {$endTime}" : $startTime) : '';

    $paragraphs = [];

    switch ($eventType) {
        case 'General Assembly':
            $paragraphs[] = "{$clubName} cordially invites all student members and aspiring leaders to the {$title}. This assembly serves as an official gathering to present key organizational milestones, inaugurate semester initiatives, and discuss upcoming co-curricular engagements designed to enhance student leadership and professional growth.";
            $paragraphs[] = "Participants will have the opportunity to engage directly with student officers and faculty advisers, gain clarity on committee programs, and contribute ideas toward academic and community projects. Active participation is strongly encouraged as we align our shared vision for the academic term.";
            break;

        case 'Competition':
        case 'Competition / Contest':
            $paragraphs[] = "{$clubName} is proud to present {$title}, an exciting competitive event designed to challenge students, sharpen problem-solving abilities, and showcase innovative skills in a collaborative academic atmosphere.";
            $paragraphs[] = "Contestants will tackle real-world scenarios and demonstrate technical aptitude under the mentorship of designated evaluators. All club members and eligible participants are invited to participate, test their capabilities, and strive for excellence.";
            break;

        case 'Workshop':
        case 'Workshop / Seminar':
        case 'Seminar':
            $paragraphs[] = "Join {$clubName} for an interactive and high-impact learning session at the {$title}. This workshop aims to equip student attendees with practical industry-relevant skills, contemporary methodology, and foundational knowledge directly applicable to academic and career endeavors.";
            $paragraphs[] = "Through guided walkthroughs, collaborative exercises, and expert-led discussions, participants will gain actionable insights and practical experience. Attendees are advised to arrive promptly to maximize hands-on learning opportunities.";
            break;

        case 'Meeting':
        case 'Officer / Committee Meeting':
            $paragraphs[] = "Official committee meeting for {$clubName} regarding {$title}. The primary purpose of this session is to review administrative agenda items, assess ongoing operational tasks, and finalize coordination logistics for forthcoming student activities.";
            $paragraphs[] = "All designated officers, committee heads, and invited members are expected to attend with prepared progress updates to facilitate timely project implementation and governance.";
            break;

        case 'Community Activity':
        case 'Community Outreach':
            $paragraphs[] = "{$clubName} is organizing {$title} as part of our ongoing commitment to community enrichment, civic participation, and social responsibility across Bestlink College of the Philippines.";
            $paragraphs[] = "Volunteers will take part in meaningful community-oriented initiatives aimed at supporting beneficiaries and fostering empathy, teamwork, and leadership outside the classroom.";
            break;

        default:
            $paragraphs[] = "{$clubName} is pleased to host {$title}, bringing together students for an enriching co-curricular experience focused on collaboration, skill enhancement, and student development.";
            $paragraphs[] = "Attendees will participate in structured activities and gain valuable perspective alongside fellow organization members and faculty mentors.";
            break;
    }

    if ($instructions !== '') {
        $paragraphs[] = "Special Note: {$instructions}";
    }

    $description = implode("\n\n", $paragraphs);
    $summary = "Official description for {$title} organized by {$clubName}.";

    return [
        'success' => true,
        'description' => $description,
        'summary' => $summary,
        'model' => 'local-fallback',
        'provider' => 'local',
        'source' => 'local',
        'fallback_used' => true,
        'generated_at' => date('Y-m-d H:i:s'),
    ];
}

/**
 * Main coordinator for Event Description generation.
 * Follows the established provider chain: Gemini -> OpenAI -> Local Fallback.
 */
function cocurricularGenerateEventDescriptionDraft(array $input, array $diagnosticContext = []): array
{
    // 1. Try Gemini
    $geminiResult = cocurricularGenerateEventDescriptionWithGemini($input, $diagnosticContext);
    if (!empty($geminiResult['success']) && !empty($geminiResult['description'])) {
        return $geminiResult;
    }

    // 2. Try OpenAI Fallback
    $openAiResult = cocurricularGenerateEventDescriptionWithOpenAi($input, $diagnosticContext);
    if (!empty($openAiResult['success']) && !empty($openAiResult['description'])) {
        return $openAiResult;
    }

    // 3. Resilient Local Synthesizer Fallback
    cocurricularLogAiDiagnostic($diagnosticContext['endpoint'] ?? 'generate-event-description', array_merge($diagnosticContext, [
        'provider' => 'local',
        'model' => 'local-fallback',
        'api_key_available' => false,
        'http_code' => 200,
        'curl_errno' => 0,
        'error_type' => 'none',
        'fallback_used' => true,
    ]));

    return cocurricularSynthesizeEventDescription($input);
}

