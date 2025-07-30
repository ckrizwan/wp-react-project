<?php
// api.php - Your React app makes requests to this script
require_once __DIR__ . '/vendor/autoload.php';

use GeminiAPI\Client;
use GeminiAPI\Resources\ModelName;
use GeminiAPI\Resources\Parts\TextPart;
use GuzzleHttp\Client as GuzzleHttpClient;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Client\ClientExceptionInterface;

// Enable all error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 'Off'); // Do not display errors to the browser output (GOOD!)
ini_set('log_errors', 'On');    // Log errors to PHP's error log
ini_set('error_log', 'php://stderr'); // Send error log to stderr, so docker logs can capture it

error_log("DEBUG: api.php script started."); // Log start of script

// Allow requests from your React app's origin.
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Max-Age: 3600");

error_log("DEBUG: CORS headers sent.");

// --- IMPORTANT: Handle preflight (OPTIONS) requests ---
// Ensure $_SERVER['REQUEST_METHOD'] is set for HTTP contexts
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] == 'OPTIONS') {

    error_log("DEBUG: OPTIONS request received. Sending 204.");
    http_response_code(204);
    exit(); // Exit after sending headers for OPTIONS request.
}

error_log("DEBUG: Processing actual request method: " . ($_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN'));


$input = json_decode(file_get_contents('php://input'), true);

$userPrompt = $input['contents'][0]['parts'][0]['text'] ?? '';

if (empty($userPrompt)) {
    error_log("ERROR: Prompt is required or could not be extracted."); // More specific log message
    echo json_encode(['error' => 'Prompt is required or could not be extracted.']);
    exit;
}
// --- Configuration ---
$geminiApiKey = getenv('GEMINI_API_KEY');
// This is the base URL for the MCP plugin's REST API, which now includes /streamable
// for JSON-RPC 2.0 interactions.
$wpMcpStreamableUrl = 'http://localhost/wp-json/wp/v2/wpmcp/streamable';
$wpMcpJwtToken = getenv('WP_MCP_JWT_TOKEN');

if (!$geminiApiKey || !$wpMcpJwtToken) {
    error_log("ERROR: API keys or tokens not configured. GEMINI_API_KEY: " . (!empty($geminiApiKey) ? 'SET' : 'NOT SET') . ", WP_MCP_JWT_TOKEN: " . (!empty($wpMcpJwtToken) ? 'SET' : 'NOT SET'));
    echo json_encode(['error' => 'API keys or tokens not configured.']);
    http_response_code(500);
    exit;
}

$geminiClient = new Client($geminiApiKey);
$httpClient = new GuzzleHttpClient();

try {
    // --- Step 1: Discover available tools from WordPress MCP Plugin (via JSON-RPC) ---
    $toolsDefinition = [];
    try {
        error_log("DEBUG: Attempting to list tools via JSON-RPC POST to " . $wpMcpStreamableUrl);
        $response = $httpClient->post($wpMcpStreamableUrl, [
            'headers' => [
                'Authorization' => 'Bearer ' . $wpMcpJwtToken,
                'Content-Type' => 'application/json', // Crucial for JSON-RPC
                'Accept' => 'application/json, text/event-stream', // ADD THIS LINE
            ],
            'json' => [ // JSON-RPC 2.0 payload for "tools/list" method
                'jsonrpc' => '2.0',
                'method' => 'tools/list',
                'id' => uniqid(), // Unique ID for the request
            ],
        ]);

        $jsonRpcResponse = json_decode($response->getBody()->getContents(), true);
        error_log("DEBUG: Raw JSON-RPC toolsList response: " . print_r($jsonRpcResponse, true));

        // Check for JSON-RPC errors first
        if (isset($jsonRpcResponse['error'])) {
            $errorCode = $jsonRpcResponse['error']['code'] ?? 'N/A';
            $errorMessage = $jsonRpcResponse['error']['message'] ?? 'Unknown JSON-RPC error';
            error_log("ERROR: MCP JSON-RPC Error when listing tools: Code {$errorCode} - {$errorMessage}");
            // Depending on how critical this is, you might want to exit or throw
            // For now, we'll log and continue as if no tools are available.
        } elseif (isset($jsonRpcResponse['result']) && is_array($jsonRpcResponse['result'])) {
            // The actual tools list is in the 'result' key of the JSON-RPC response
            $toolsList = $jsonRpcResponse['result'];
            error_log("DEBUG: Parsed toolsList (from result): " . print_r($toolsList, true));

            if (isset($toolsList['tools']) && is_array($toolsList['tools'])) {
                foreach ($toolsList['tools'] as $tool) {
                    $functionDeclaration = [
                        'name' => $tool['name'],
                        'description' => $tool['description'] ?? "A WordPress MCP tool.",
                        'parameters' => [
                            'type' => 'object',
                            'properties' => [],
                            'required' => []
                        ]
                    ];

                    if (isset($tool['arguments_schema']['properties'])) {
                        foreach ($tool['arguments_schema']['properties'] as $paramName => $paramDetails) {
                            $functionDeclaration['parameters']['properties'][$paramName] = [
                                'type' => $paramDetails['type'] ?? 'string',
                                'description' => $paramDetails['description'] ?? '',
                            ];
                            // Handle potential 'required' property for parameters
                            if (isset($paramDetails['required']) && $paramDetails['required']) {
                                $functionDeclaration['parameters']['required'][] = $paramName;
                            }
                        }
                    }
                    $toolsDefinition[] = $functionDeclaration;
                }
            } else {
                error_log("DEBUG: toolsList array found, but no 'tools' key or it's not an array.");
            }
        } else {
            error_log("WARNING: JSON-RPC response for tools/list did not contain a 'result' key or it was not an array.");
        }
    } catch (ClientException $e) {
        $responseBody = $e->getResponse() ? $e->getResponse()->getBody()->getContents() : 'No response body';
        $statusCode = $e->getResponse() ? $e->getResponse()->getStatusCode() : 'N/A';
        error_log(
            "ERROR: Guzzle ClientException when listing WordPress MCP tools: Status {$statusCode} - " . $e->getMessage() . " - Body: " . $responseBody
        );
        // Depending on your error strategy, you might want to exit or throw here if tools are essential.
    } catch (GuzzleException $e) {
        error_log('ERROR: GuzzleException during tool list: ' . $e->getMessage());
    } catch (Exception $e) {
        error_log('ERROR: General Exception during tool list: ' . $e->getMessage());
    }


    // --- Step 2: Configure the model with tools BEFORE starting the chat ---
    $modelOptions = [
        'model' => ModelName::GEMINI_1_5_FLASH,
    ];

    if (!empty($toolsDefinition)) {
        // Pass tools directly as a 'tools' option in the model configuration
        $modelOptions['tools'] = $toolsDefinition;
        error_log("DEBUG: Tools defined and attached to model.");
    } else {
        error_log("DEBUG: No tools defined or attached to model.");
    }

    // Instantiate the generative model with the tools.
    $modelWithTools = $geminiClient->generativeModel($modelOptions);

    $chat = $modelWithTools->startChat(); // Start chat with the tool-configured model.


    // --- Send the initial message (text part only) ---
    $geminiResponse = $chat->sendMessage(new TextPart($userPrompt));

    $toolCalls = $geminiResponse->candidates[0]->content->parts[0]->toolCalls ?? [];

    if (!empty($toolCalls)) {
        // --- Step 3: Gemini wants to call a tool! Execute it via WordPress MCP (JSON-RPC) ---
        $toolResponsesForGeminiParts = []; // Collect TextPart objects representing tool responses
        foreach ($toolCalls as $toolCall) {
            $toolName = $toolCall->function->name;
            $toolArgs = (array)$toolCall->function->args;

            error_log("DEBUG: Gemini requested tool: " . $toolName . " with args: " . json_encode($toolArgs));

            try {
                error_log("DEBUG: Attempting to call tool '$toolName' via JSON-RPC POST to " . $wpMcpStreamableUrl);
                $mcpCallResponse = $httpClient->post($wpMcpStreamableUrl, [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $wpMcpJwtToken,
                        'Content-Type' => 'application/json', // Crucial for JSON-RPC
                        'Accept' => 'application/json, text/event-stream', // ADD THIS LINE
                    ],
                    'json' => [ // JSON-RPC 2.0 payload for "tools/call" method
                        'jsonrpc' => '2.0',
                        'method' => 'tools/call', // The JSON-RPC method name
                        'params' => [ // Parameters for the "tools/call" method
                            'name' => $toolName,
                            'arguments' => $toolArgs,
                        ],
                        'id' => uniqid(), // Unique ID for the request
                    ],
                ]);

                $jsonRpcCallResult = json_decode($mcpCallResponse->getBody()->getContents(), true);
                error_log("DEBUG: Raw JSON-RPC tool call result: " . print_r($jsonRpcCallResult, true));

                if (isset($jsonRpcCallResult['error'])) {
                    $errorCode = $jsonRpcCallResult['error']['code'] ?? 'N/A';
                    $errorMessage = $jsonRpcCallResult['error']['message'] ?? 'Unknown JSON-RPC error';
                    $toolError = "Tool '$toolName' failed via JSON-RPC: Code {$errorCode} - {$errorMessage}";
                    error_log("ERROR: " . $toolError);
                    $toolResponsesForGeminiParts[] = new TextPart($toolError);
                } elseif (isset($jsonRpcCallResult['result'])) {
                    $mcpResult = $jsonRpcCallResult['result']; // Extract the 'result' key
                    error_log("DEBUG: Tool '$toolName' result (from result): " . json_encode($mcpResult));
                    $toolResponsesForGeminiParts[] = new TextPart("Tool '$toolName' executed with result: " . json_encode($mcpResult));
                } else {
                    error_log("WARNING: JSON-RPC response for tool '$toolName' did not contain a 'result' key or an 'error' key.");
                    $toolResponsesForGeminiParts[] = new TextPart("Tool '$toolName' executed but returned unexpected JSON-RPC response.");
                }


            } catch (ClientException $e) {
                $errorMessage = "Failed to call WordPress MCP tool '$toolName' (ClientException): " . $e->getMessage() . " - " . ($e->hasResponse() ? $e->getResponse()->getBody()->getContents() : 'No response body');
                error_log("ERROR: " . $errorMessage);
                $toolResponsesForGeminiParts[] = new TextPart("Tool '$toolName' failed: " . $errorMessage);
            } catch (GuzzleException $e) {
                error_log('ERROR: GuzzleException during tool call: ' . $e->getMessage());
                $toolResponsesForGeminiParts[] = new TextPart("Tool '$toolName' Guzzle Error: " . $e->getMessage());
            } catch (Exception $e) {
                error_log('FATAL: Unexpected error during tool call: ' . $e->getMessage());
                $toolResponsesForGeminiParts[] = new TextPart("Tool '$toolName' Unexpected Error: " . $e->getMessage());
            }
        }

        // --- Step 4: Send tool results back to Gemini ---
        // Ensure there's always at least one part for sendMessage, even if tool results are empty or failed.
        // A direct text part indicating tool execution attempts/results is helpful.
        $initialMessagePart = new TextPart("Attempted tool execution. Here are the tool results or messages:");
        $partsToFinalSend = array_merge([$initialMessagePart], $toolResponsesForGeminiParts);


        if (!empty($partsToFinalSend)) {
            error_log("DEBUG: Sending tool results back to Gemini. Parts count: " . count($partsToFinalSend));
            $finalGeminiResponse = $chat->sendMessage(...$partsToFinalSend);
            echo json_encode(['response' => $finalGeminiResponse->text()]);
        } else {
            error_log("WARNING: No valid parts to send back to Gemini after tool calls.");
            echo json_encode(['error' => 'No valid tool responses to send back to AI.']);
            http_response_code(500);
        }

    } else {
        // No tool call, Gemini responded directly
        error_log("DEBUG: Gemini responded directly. No tool calls.");
        echo json_encode(['response' => $geminiResponse->text()]);
    }
} catch (ClientExceptionInterface $e) {
    error_log('ERROR: ClientExceptionInterface (network/HTTP error with Gemini or WordPress): ' . $e->getMessage());
    echo json_encode(['error' => 'A client/network error occurred with Gemini or WordPress.']);
    http_response_code(500);
} catch (Exception $e) {
    error_log("FATAL: General API Error: " . $e->getMessage() . " on line " . $e->getLine());
    echo json_encode(['error' => 'An internal server error occurred.']);
    http_response_code(500);
}