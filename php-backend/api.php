<?php
// Current time is Thursday, July 31, 2025 at 1:57 PM PKT.
require_once __DIR__ . '/vendor/autoload.php';

use GuzzleHttp\Client as GuzzleHttpClient;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;

// --- Error Reporting & Logging ---
error_reporting(E_ALL);
ini_set('display_errors', 'Off'); // Do not display errors to the browser in production
ini_set('log_errors', 'On');     // Log errors to php://stderr (Docker logs)
ini_set('error_log', 'php://stderr'); // Direct error logs to Docker stdout/stderr

// --- CORS Headers ---
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Max-Age: 3600"); // Cache preflight requests for 1 hour

// Handle OPTIONS requests (preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204); // No Content
    exit;
}

// --- Input Parsing ---
$input = json_decode(file_get_contents('php://input'), true);
$userPrompt = $input['contents'][0]['parts'][0]['text'] ?? '';

if (empty($userPrompt)) {
    echo json_encode(['error' => 'Prompt is required']);
    http_response_code(400); // Bad Request
    exit;
}

// --- Environment Variables ---
$geminiApiKey = getenv('GEMINI_API_KEY');
// Using 'wordpress' as the service name in Docker Compose for internal communication
$wpMcpStreamableUrl = 'http://wordpress/wp-json/wp/v2/wpmcp/streamable';
$wpMcpJwtToken = getenv('WP_MCP_JWT_TOKEN');

// --- Debugging Environment Variables ---
/*error_log("DEBUG: GEMINI_API_KEY value: " . (empty($geminiApiKey) ? "[EMPTY]" : substr($geminiApiKey, 0, 5) . '...'));
error_log("DEBUG: WP_MCP_JWT_TOKEN value: " . (empty($wpMcpJwtToken) ? "[EMPTY]" : "SET") . '...');*/

if (!$geminiApiKey || (!$wpMcpJwtToken && strpos(strtolower($userPrompt), 'blog post') !== false)) { // Only require token if prompt suggests blog post interaction
    error_log("ERROR: Missing API keys or tokens. GEMINI_API_KEY is " . (empty($geminiApiKey) ? "EMPTY" : "SET") . ", WP_MCP_JWT_TOKEN is " . (empty($wpMcpJwtToken) ? "EMPTY" : "SET"));
    echo json_encode(['error' => 'Server configuration error: Missing API keys or tokens for WordPress interaction.']);
    http_response_code(500); // Internal Server Error
    exit;
}

$httpClient = new GuzzleHttpClient();
$toolsForGemini = []; // This will hold the correctly formatted tools for Gemini

// --- Step 1: Fetch tools from WordPress MCP ---
try {
    // Only attempt to fetch tools if the prompt suggests WordPress interaction
    if (strpos(strtolower($userPrompt), 'blog post') !== false || strpos(strtolower($userPrompt), 'post') !== false || strpos(strtolower($userPrompt), 'article') !== false || strpos(strtolower($userPrompt), 'search') !== false || strpos(strtolower($userPrompt), 'find') !== false || strpos(strtolower($userPrompt), 'list') !== false || strpos(strtolower($userPrompt), 'retrieve') !== false) {
        error_log("DEBUG: Attempting to fetch tools from MCP at: " . $wpMcpStreamableUrl);
        $toolsResp = $httpClient->post($wpMcpStreamableUrl, [
            'headers' => [
                'Authorization' => 'Bearer ' . $wpMcpJwtToken,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json, text/event-stream' // Required by MCP
            ],
            'json' => [
                'jsonrpc' => '2.0',
                'method' => 'tools/list',
                'id' => uniqid(),
            ],
            'timeout' => 10 // Added timeout for MCP request
        ]);
        $toolsJson = json_decode($toolsResp->getBody()->getContents(), true);
        error_log("DEBUG: Raw MCP tools/list response: " . json_encode($toolsJson, JSON_PRETTY_PRINT));

        $toolsList = $toolsJson['result']['tools'] ?? [];

        foreach ($toolsList as $tool) {
            error_log("DEBUG: Processing tool from MCP (name: " . ($tool['name'] ?? 'N/A') . "): " . json_encode($tool, JSON_PRETTY_PRINT));

            $functionDescription = $tool['description'] ?? 'WordPress MCP tool.';

            // --- ENHANCED DESCRIPTION LOGIC ---
            $toolNameLower = strtolower($tool['name']);
            if ($toolNameLower === 'wp_posts_search') {
                $functionDescription = "Retrieves and searches WordPress blog posts by keyword (using the 'search' parameter), category, author, or other filters. This tool has direct, internal, and pre-configured access to the WordPress blog and does not require a URL, login, or any external credentials from the user. Use this tool when the user asks to find, list, or get information about blog posts, especially when looking for specific topics or content.";
            } elseif ($toolNameLower === 'wp_get_post') {
                $functionDescription = "Retrieves the full content of a single WordPress blog post by its unique ID (using the 'id' parameter). This tool has direct, internal, and pre-configured access to the WordPress blog and does not require a URL, login, or any external credentials from the user. Use this tool when the user asks to retrieve a *specific* post, usually by its ID or exact title.";
            } else {
                // Fallback for other tools if their descriptions are not explicitly tailored
                // Keep the previous logic here for other tools, or refine as needed
                if (str_contains($toolNameLower, 'post') && (str_contains($toolNameLower, 'search') || str_contains($toolNameLower, 'find') || str_contains($toolNameLower, 'list') || str_contains($toolNameLower, 'get') || str_contains($toolNameLower, 'retrieve'))) {
                    $functionDescription = "Retrieves and searches WordPress blog posts. This tool has direct, internal, and pre-configured access to the WordPress blog and **does not require a URL, login, or any external credentials from the user**. It can search by keyword, category, author, or other filters. Use this tool when the user asks to find, list, or get information about blog posts. Do not attempt to create or publish posts, only retrieve existing content.";
                }
            }


            $functionDeclaration = [
                'name' => $tool['name'],
                'description' => $functionDescription,
            ];

            $hasParameters = false;
            $geminiParameters = [
                'type' => 'object',
                'properties' => [],
                'required' => [],
            ];

            // --- CRITICAL FIX: Changed arguments_schema to inputSchema ---
            if (isset($tool['inputSchema']['properties']) && is_array($tool['inputSchema']['properties'])) {
                if (!empty($tool['inputSchema']['properties'])) {
                    foreach ($tool['inputSchema']['properties'] as $paramName => $paramDetails) {
                        $paramType = $paramDetails['type'] ?? 'string';
                        $paramDescription = $paramDetails['description'] ?? '';

                        // --- ENHANCED PARAMETER DESCRIPTION LOGIC ---
                        $paramNameLower = strtolower($paramName);
                        if (empty($paramDescription)) {
                            switch ($paramNameLower) {
                                case 'search': // This is the 'keyword' for wp_posts_search
                                    $paramDescription = "The keyword or phrase to search for within post titles or content.";
                                    break;
                                case 'id': // This is for wp_get_post
                                    $paramDescription = "The unique ID of the post to retrieve.";
                                    break;
                                case 'context':
                                    $paramDescription = "Scope of the request (e.g., 'view', 'embed'). Default is 'view'.";
                                    break;
                                case 'page':
                                    $paramDescription = "Current page number for paginated results.";
                                    break;
                                case 'per_page':
                                    $paramDescription = "Maximum number of items to return per page (max 100).";
                                    break;
                                case 'after':
                                case 'before':
                                case 'modified_after':
                                case 'modified_before':
                                    $paramDescription = "ISO8601 date to filter posts by publish or modification date.";
                                    break;
                                case 'author':
                                case 'author_exclude':
                                    $paramDescription = "Author ID(s) to include or exclude.";
                                    break;
                                case 'exclude':
                                case 'include':
                                    $paramDescription = "Post ID(s) to exclude or include.";
                                    break;
                                case 'offset':
                                    $paramDescription = "Number of items to skip from the beginning.";
                                    break;
                                case 'order':
                                    $paramDescription = "Order results ('asc' or 'desc').";
                                    break;
                                case 'orderby':
                                    $paramDescription = "Field to order results by (e.g., 'date', 'title', 'relevance').";
                                    break;
                                case 'search_columns':
                                    $paramDescription = "Specific columns to search within (e.g., 'post_title', 'post_content').";
                                    break;
                                case 'slug':
                                    $paramDescription = "Post slug(s) to filter by.";
                                    break;
                                case 'status':
                                    $paramDescription = "Post status(es) to filter by (e.g., 'publish', 'draft').";
                                    break;
                                case 'categories':
                                case 'categories_exclude':
                                    $paramDescription = "Category ID(s) or slug(s) to include or exclude.";
                                    break;
                                case 'tags':
                                case 'tags_exclude':
                                    $paramDescription = "Tag ID(s) or slug(s) to include or exclude.";
                                    break;
                                case 'sticky':
                                    $paramDescription = "Filter by sticky posts.";
                                    break;
                                case 'ignore_sticky':
                                    $paramDescription = "Whether to ignore sticky posts in results.";
                                    break;
                                case 'format':
                                    $paramDescription = "Post format(s) to filter by.";
                                    break;
                                case 'excerpt_length':
                                    $paramDescription = "Override the default excerpt length when retrieving a post.";
                                    break;
                                case 'password':
                                    $paramDescription = "The password for a password-protected post.";
                                    break;
                                default:
                                    $paramDescription = "A parameter for the " . $tool['name'] . " tool.";
                                    break;
                            }
                        }

                        $geminiParameters['properties'][$paramName] = [
                            'type' => $paramType,
                            'description' => $paramDescription,
                        ];

                        // --- ADD THIS NEW BLOCK ---
                        // If the parameter is an array, ensure its 'items' schema is also copied
                        if ($paramType === 'array' && isset($paramDetails['items'])) {
                            $geminiParameters['properties'][$paramName]['items'] = $paramDetails['items'];
                        }
                        // --- END NEW BLOCK ---
                    }
                    $hasParameters = true;
                }
            }
            // --- CRITICAL FIX: Changed arguments_schema to inputSchema for required fields too ---
            if (isset($tool['inputSchema']['required']) && is_array($tool['inputSchema']['required'])) {
                if (!empty($tool['inputSchema']['required'])) {
                    $geminiParameters['required'] = $tool['inputSchema']['required'];
                    $hasParameters = true;
                }
            }

            // Only add the 'parameters' key if there are actual properties or required fields
            if ($hasParameters) {
                $functionDeclaration['parameters'] = $geminiParameters;
            }

            // Gemini expects an array of 'tool' objects, each containing 'function_declarations'
            $toolsForGemini[] = ['function_declarations' => [$functionDeclaration]];
        }
        error_log("DEBUG: Tools prepared for Gemini: " . json_encode($toolsForGemini, JSON_PRETTY_PRINT));
    }

} catch (ClientException $e) {
    $responseBody = $e->getResponse() ? $e->getResponse()->getBody()->getContents() : 'No response body';
    error_log("ERROR: MCP Tool fetch client error: " . $e->getMessage() . "\nResponse Body: " . $responseBody);
    echo json_encode(['error' => 'Failed to fetch tools from WordPress (Client Error).']);
    http_response_code(500);
    exit;
} catch (GuzzleException $e) {
    error_log("ERROR: MCP Tool fetch network error: " . $e->getMessage());
    echo json_encode(['error' => 'Failed to fetch tools from WordPress (Network Error).']);
    http_response_code(500);
    exit;
} catch (Exception $e) {
    error_log("ERROR: MCP Tool fetch general error: " . $e->getMessage());
    echo json_encode(['error' => 'Failed to fetch tools from WordPress (General Error).']);
    http_response_code(500);
    exit;
}

// --- Step 2: Send prompt to Gemini API with Tools ---
try {
    // --- UPDATED PRIMING INSTRUCTION ---
    $initialContents = [
        [
            // System instruction disguised as user turn
            'role' => 'user',
            'parts' => [
                ['text' => 'You are an AI assistant capable of interacting with a WordPress blog by using specific tools.

                **ABSOLUTE AND CRITICAL INSTRUCTION: Your final response to the user MUST ALWAYS BE in natural, human-readable language. NEVER, under any circumstances, output raw tool code, `functionCall` blocks, or any JSON structure representing a tool call directly to the user.** These are for your internal use only.

                Your process should be:
                1.  **Prioritize WordPress Search:** When the user asks for content, first attempt to find it in the WordPress blog using available tools like `wp_posts_search` or `wp_get_post`.
                    * **For searching content:** Use the `wp_posts_search` tool. The **ONLY** parameter for keywords is `search`. Your internal `functionCall` MUST be like: `{"name": "wp_posts_search", "args": {"search": "user\'s keywords"}}`.
                    * **For getting a specific post by ID:** Use the `wp_get_post` tool. The parameter is `id`. Your internal `functionCall` MUST be like: `{"name": "wp_get_post", "args": {"id": 123}}`.
                2.  **Process Results & Respond (Natural Language):**
                    * **If content is found:** Present the retrieved content or a concise summary of it to the user in a clear, natural language response.
                    * **If content is NOT found (tool returns empty results):**
                        a.  Clearly state that no matching blog post was found (e.g., "I couldn\'t find any blog posts on [user\'s initial topic].").
                        b.  **Immediately generate new content for a blog post on that specific topic**, based on the user\'s initial request.
                        c.  Present the generated content directly to the user, introducing it by stating that since no existing post was found, this content has been generated for them. For example: "Since I couldn\'t find an existing post on that topic, here\'s a generated one for you: [Generated Content]."

                Always ensure your final message to the user is helpful, easy to understand, and *never* contains tool code.' ]
            ]
        ],
        [ // This is the actual user prompt from the frontend
            'role' => 'user',
            'parts' => [
                ['text' => $userPrompt]
            ]
        ]
    ];


    $geminiRequestBody = [
        'contents' => $initialContents, // Use the new simplified contents
        'tools' => $toolsForGemini, // Use the correctly formatted tools for Gemini
        'safety_settings' => [ // Optional: Adjust safety settings if needed
            ['category' => 'HARM_CATEGORY_HARASSMENT', 'threshold' => 'BLOCK_NONE'],
            ['category' => 'HARM_CATEGORY_HATE_SPEECH', 'threshold' => 'BLOCK_NONE'],
            ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_NONE'],
            ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_NONE'],
        ],
        'generation_config' => [ // Optional: Adjust generation config
            'temperature' => 0.7,
            'topP' => 1.0,
            'topK' => 40,
        ],
    ];
    error_log("DEBUG: Sending request to Gemini: " . json_encode($geminiRequestBody, JSON_PRETTY_PRINT));

    $geminiResponse = $httpClient->post(
        'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent',
        [
            'headers' => [
                'x-goog-api-key' => $geminiApiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => $geminiRequestBody,
            'timeout' => 30
        ]
    );

    $responseBody = json_decode($geminiResponse->getBody()->getContents(), true);
    error_log("DEBUG: Raw Gemini response: " . json_encode($responseBody, JSON_PRETTY_PRINT));

    // Get candidate content and check for function calls
    $candidateContent = $responseBody['candidates'][0]['content'] ?? null;
    $toolCallFromGemini = $candidateContent['parts'][0]['functionCall'] ?? null;
    $toolCallsArray = $toolCallFromGemini ? [$toolCallFromGemini] : [];

    // --- CRITICAL FIX FOR EMPTY ARGS (Keep this, as Gemini might still send empty args) ---
    if ($toolCallFromGemini && isset($toolCallFromGemini['args']) && is_array($toolCallFromGemini['args']) && empty($toolCallFromGemini['args'])) {
        // Ensure empty 'args' array is sent as an empty JSON object {}
        $toolCallFromGemini['args'] = (object)[];
        error_log("DEBUG: Corrected empty toolCallFromGemini['args'] to empty object.");
    }
    // --- END CRITICAL FIX ---


    // --- Step 3: If Gemini calls tools ---
    if (!empty($toolCallsArray)) {
        error_log("DEBUG: Gemini has requested tool execution.");
        $toolResults = [];
        foreach ($toolCallsArray as $toolCall) {
            $toolName = $toolCall['name'] ?? '';
            // Ensure args is an object for JSON RPC call if it came in as array
            $toolArgs = is_array($toolCall['args']) ? (object)$toolCall['args'] : ($toolCall['args'] ?? new stdClass());


            error_log("DEBUG: Calling MCP tool: '" . $toolName . "' with args: " . json_encode($toolArgs));

            try {
                $toolResp = $httpClient->post($wpMcpStreamableUrl, [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $wpMcpJwtToken,
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json, text/event-stream',
                    ],
                    'json' => [
                        'jsonrpc' => '2.0',
                        'method' => 'tools/call',
                        'params' => [
                            'name' => $toolName,
                            'arguments' => $toolArgs, // Send as object for consistency
                        ],
                        'id' => uniqid(),
                    ],
                    'timeout' => 10
                ]);
                $toolJson = json_decode($toolResp->getBody()->getContents(), true);
                $toolResult = $toolJson['result'] ?? ['status' => 'No result from MCP'];
                error_log("DEBUG: MCP tool '" . $toolName . "' returned: " . json_encode($toolResult, JSON_PRETTY_PRINT));

                // Format the tool result for Gemini's FunctionResponsePart
                $toolResults[] = [
                    'functionResponse' => [
                        'name' => $toolName,
                        'response' => $toolResult
                    ]
                ];
            } catch (ClientException $e) {
                $responseBody = $e->getResponse() ? $e->getResponse()->getBody()->getContents() : 'No response body';
                error_log("ERROR: MCP Tool '" . $toolName . "' client error: " . $e->getMessage() . "\nResponse Body: " . $responseBody);
                $toolResults[] = [
                    'functionResponse' => [
                        'name' => $toolName,
                        'response' => ['error' => 'MCP Client Error: ' . $e->getMessage()]
                    ]
                ];
            } catch (GuzzleException $e) {
                error_log("ERROR: MCP Tool '" . $toolName . "' network error: " . $e->getMessage());
                $toolResults[] = [
                    'functionResponse' => [
                        'name' => $toolName,
                        'response' => ['error' => 'MCP Network Error: ' . $e->getMessage()]
                    ]
                ];
            } catch (Exception $e) {
                error_log("ERROR: MCP Tool '" . $toolName . "' general error: " . $e->getMessage());
                $toolResults[] = [
                    'functionResponse' => [
                        'name' => $toolName,
                        'response' => ['error' => 'MCP General Error: ' . $e->getMessage()]
                    ]
                ];
            }
        }

        // --- Step 4: Send results back to Gemini for the final response ---
        // This constructs the full conversation history for the next turn
        $conversationHistory = [
            // User's initial system instruction (index 0) - Always include the latest instruction
            [
                'role' => 'user',
                'parts' => [
                    ['text' => $initialContents[0]['parts'][0]['text'] ] // Reuse the full, updated instruction
                ]
            ],
            [ // User's initial prompt (index 1)
                'role' => 'user',
                'parts' => [
                    ['text' => $userPrompt]
                ]
            ]
        ];

        // Add model's tool call and user's tool results to history
        if ($toolCallFromGemini) { // Ensure toolCallFromGemini is valid before adding
            $conversationHistory[] = [
                'role' => 'model',
                'parts' => [
                    ['functionCall' => $toolCallFromGemini]
                ]
            ];
            $conversationHistory[] = [
                'role' => 'user',
                'parts' => $toolResults
            ];
        }


        $finalGeminiRequestBody = [
            'contents' => $conversationHistory,
            'tools' => $toolsForGemini, // Tools must be sent in every turn if needed
            'safety_settings' => $geminiRequestBody['safety_settings'], // Reuse settings
            'generation_config' => $geminiRequestBody['generation_config'], // Reuse settings
        ];
        error_log("DEBUG: Sending tool results back to Gemini for final response: " . json_encode($finalGeminiRequestBody, JSON_PRETTY_PRINT));

        $finalResponse = $httpClient->post(
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent',
            [
                'headers' => [
                    'x-goog-api-key' => $geminiApiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => $finalGeminiRequestBody,
                'timeout' => 30
            ]
        );

        $finalBody = json_decode($finalResponse->getBody()->getContents(), true);
        error_log("DEBUG: Final Gemini response after tool execution: " . json_encode($finalBody, JSON_PRETTY_PRINT));
        echo json_encode(['response' => $finalBody['candidates'][0]['content']['parts'][0]['text'] ?? 'No final response from Gemini.']);

    } else {
        // No tool calls, return Gemini’s direct text response
        error_log("DEBUG: Gemini responded directly (no tool calls requested).");
        echo json_encode(['response' => $responseBody['candidates'][0]['content']['parts'][0]['text'] ?? 'No direct text response from Gemini.']);
    }
} catch (ClientException $e) {
    $responseBody = $e->getResponse() ? $e->getResponse()->getBody()->getContents() : 'No response body';
    error_log("ERROR: Gemini API client error: " . $e->getMessage() . "\nResponse Body: " . $responseBody);
    echo json_encode(['error' => 'Gemini API client error: ' . $e->getMessage() . ' - Response: ' . $responseBody]);
    http_response_code(500);
} catch (GuzzleException $e) {
    error_log("ERROR: Gemini API network error: " . $e->getMessage());
    echo json_encode(['error' => 'Gemini API network or Guzzle error: ' . $e->getMessage()]);
    http_response_code(500);
} catch (Exception $e) {
    error_log("ERROR: Gemini API general error: " . $e->getMessage());
    echo json_encode(['error' => 'An unexpected error occurred: ' . $e->getMessage()]);
    http_response_code(500);
}